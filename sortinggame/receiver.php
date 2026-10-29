<?php
require_once("config.php");
require_once("../identity.php");

$user = $_SESSION['user_id'];
$daily = '/^\d{4}-\d\d-\d\d-/'; // a daily's seed is its date and language, e.g. 2026-10-24-de

// Today's daily in a language is created by its first player, under a lock so two first players can't create two:
// a chain of all category types, 9 items on weekdays, 16 on Saturdays, 25 on Sundays
if (isset($_GET['daily']))
{
	$mysql->query("select get_lock('daily', 10)");
	if ($mysql->execute_query("select 1 from game where seed = ? limit 1", [date('Y-m-d') . '-' . $_GET['daily']])->fetch_row())
	{
		header("Location: ./?go=daily&lang=$_GET[daily]");
		exit;
	}
	$_POST = ['action' => 'create', 'seed' => date('Y-m-d') . '-' . $_GET['daily'], 'language' => $_GET['daily'], 'mode' => 'chain', 'size' => [25, 9, 9, 9, 9, 9, 16][date('w')],
		'types' => array_column($mysql->query("select distinct type from category")->fetch_all(), 0)];
}
// Polls of a multiplayer game: the board and the other players, unless nothing changed since the client's version
elseif ($_SERVER['REQUEST_METHOD'] == 'GET')
{
	session_write_close(); // a held session would block this player's merges until the poll is answered
	$game = $mysql->execute_query("select mode, board, items, version, finished_at from game where seed = ? and multiplayer", [$_GET['seed']])->fetch_assoc();
	if ($game['version'] == $_GET['version'])
	{
		http_response_code(304);
		exit;
	}
	$board = json_decode($game['board']);
	$items = json_decode($game['items']);
	$done = (object)[];
	$links = null;
	if ($game['mode'] == 'chain')
	{
		// The client gets each word's previous one in its cluster and the link's category; a finished chain is done with the category that closes the cycle
		$flags = $board;
		$board = array_fill(0, count($items), -1);
		$links = array_fill(0, count($items), null);
		foreach ($flags as $i => $made) if ($made) [$board[$items[$i][2]], $links[$items[$i][2]]] = [$i, $items[$i][1]];
		if (count(array_filter($flags)) == count($flags) - 1)
		{
			$u = array_search(false, $flags, true);
			$done->{$items[$u][2]} = $items[$u][1];
		}
	}
	else
	{
		// A category is complete when only one of its words starts a cluster
		$heads = array_keys($board, -1, true);
		$count = array_count_values(array_map(fn($i) => $items[$i][1], $heads));
		foreach ($heads as $i) if ($count[$items[$i][1]] == 1) $done->$i = $items[$i][1];
	}
	$players = $mysql->execute_query("select display_name, score, mistakes from player where seed = ? and user_id <> ? order by score desc", [$_GET['seed'], $user])->fetch_all(MYSQLI_ASSOC);
	echo json_encode(['version' => $game['version'], 'board' => $board, 'done' => $done, 'links' => $links, 'finished' => $game['finished_at'] !== null, 'players' => $players]);
	exit;
}

$seed = $_POST['seed'] ?? bin2hex(random_bytes(4)); // new games get a random id
switch ($_POST['action'])
{
	case 'create':
		if (isset($_POST['multiplayer']) and !$user)
		{
			header("Location: ./?go=game&guest");
			break;
		}
		identify();
		$_POST['mode'] ??= 'sorting';
		$size = (int)$_POST['size'];
		if ($_POST['mode'] == 'chain')
		{
			// Randomized depth-first search for a cycle of size words, each linked to the next by a playable category of the selected types, no two with the same parent.
			// Only non-obscure memberships count. A category that holds two board words holds just the two of a link; categories of 100 items or more are too general to matter, unless they are a link.
			// The cycle starts with a pair category's two words. Small categories (under 5 items) are mostly tried first, and for a big link, half the time the words that are in a small category.
			// A search gives up after 2000 steps and restarts at another pair: the first 20 searches need 60% small links, the next 20 half, after 60 the chain gets one word shorter.
			$name = $group = $of = $link = $count = $smallWord = [];
			foreach ($mysql->execute_query("select c.id, c.name, c.type, c.parent_id, c.playable, m.item from membership m join category c on c.id = m.category_id where c.language = ? and not m.obscure", [$_POST['language']]) as $r)
			{
				$name[$r['id']] = $r['name'];
				$group[$r['id']] = $r['parent_id'] ?? 'c' . $r['id']; // categories without a parent are a group of their own
				$of[$r['item']][$r['id']] = true;
				$count[$r['id']] = ($count[$r['id']] ?? 0) + 1;
				if ($r['playable'] and in_array($r['type'], $_POST['types'])) $link[$r['id']][] = $r['item'];
			}
			$small = array_map(fn($k) => $k < 5, $count);
			foreach ($link as $c => $ws) if ($small[$c]) foreach ($ws as $w) $smallWord[$w] = true;
			// No word comes back with the category name of one of its links on the last 14 chain boards
			$blocked = [];
			foreach ($mysql->execute_query("select max(items) items from game where mode = 'chain' and language = ? group by seed order by min(created_at) desc limit 14", [$_POST['language']]) as $r)
			{
				$prev = json_decode($r['items']);
				foreach ($prev as [$w, $cn, $next]) $blocked[$cn][$w] = $blocked[$cn][$prev[$next][0]] = true;
			}
			$pairs = array_keys(array_filter($link, fn($ws, $c) => count($ws) == 2 && !isset($blocked[$name[$c]][$ws[0]]) && !isset($blocked[$name[$c]][$ws[1]]), ARRAY_FILTER_USE_BOTH));
			$search = function() use (&$search, &$path, &$on, &$in, &$taken, &$picked, &$steps, &$n, &$big, &$maxBig, &$close, $of, $link, $group, $count, $small, $smallWord, $name, $blocked)
			{
				if (++$steps > 2000) return false;
				$x = end($path);
				$last = count($path) == $n - 1;
				$order = [];
				foreach ($of[$x] as $c => $_) if (isset($link[$c]) && !isset($taken[$group[$c]]) && !isset($blocked[$name[$c]][$x]) && ($small[$c] || $big < $maxBig)) $order[$c] = mt_rand() * ($small[$c] ? 12 : 1);
				arsort($order);
				foreach ($order as $c => $_)
				{
					$ys = $link[$c];
					shuffle($ys);
					if (!$small[$c] && mt_rand(0, 1)) $ys = [...array_filter($ys, fn($y) => isset($smallWord[$y])), ...array_filter($ys, fn($y) => !isset($smallWord[$y]))];
					foreach ($ys as $y)
					{
						// The last word needs a link back to the first one
						if (isset($on[$y]) || isset($blocked[$name[$c]][$y]) || $last && !isset($close[$y])) continue;
						// y may share a category with x, or with the first word when it is the last one, but with no other board word; a big link category holds no other board word at all
						foreach ($of[$y] as $d => $_) if (!empty($in[$d]) && $in[$d] !== [$x] && !($last && $in[$d] === [$path[0]])) continue 2;
						if ($count[$c] >= 100) foreach ($path as $p) if ($p !== $x && isset($of[$p][$c])) continue 2;
						$taken[$group[$c]] = true;
						$path[] = $y;
						$on[$y] = true;
						$picked[] = $c;
						$big += !$small[$c];
						if ($count[$c] >= 100) $in[$c] = [$x];
						foreach ($of[$y] as $d => $_) if ($count[$d] < 100 || isset($in[$d])) $in[$d][] = $y;
						if (!$last)
						{
							if ($search()) return true;
						}
						else foreach ($of[$y] as $d => $_) if (isset($link[$d]) && !isset($taken[$group[$d]]) && ($small[$d] || $big < $maxBig) && isset($of[$path[0]][$d]) && !isset($blocked[$name[$d]][$y]) && !isset($blocked[$name[$d]][$path[0]]))
						{
							if ($count[$d] >= 100) foreach ($path as $p) if ($p !== $y && $p !== $path[0] && isset($of[$p][$d])) continue 2;
							$picked[] = $d;
							return true;
						}
						foreach ($of[$y] as $d => $_) if ($count[$d] < 100 || isset($in[$d])) array_pop($in[$d]);
						if ($count[$c] >= 100) unset($in[$c]);
						$big -= !$small[$c];
						unset($taken[$group[$c]], $on[$y]);
						array_pop($path);
						array_pop($picked);
					}
				}
				return false;
			};
			for ($n = $size; ; $n--) for ($try = 0; $try < 60; $try++)
			{
				$maxBig = [intdiv(2 * $n, 5), intdiv($n, 2), $n][intdiv($try, 20)];
				$c = $pairs[array_rand($pairs)];
				[$path, $on, $in, $taken, $picked, $steps, $big, $close] = [$link[$c], array_fill_keys($link[$c], true), [], [$group[$c] => true], [$c], 0, 0, []];
				foreach ($path as $p) foreach ($of[$p] as $d => $_) if ($count[$d] < 100) $in[$d][] = $p;
				foreach ($of[$path[0]] as $d => $_) if (isset($link[$d])) foreach ($link[$d] as $y) $close[$y] = true;
				if ($search()) break 2;
			}
			// Words go on the board in random order, each with its link's category and the board index of the next word in the cycle
			$slot = range(0, $n - 1);
			shuffle($slot);
			$items = [];
			foreach ($path as $p => $w) $items[$slot[$p]] = [$w, $name[$picked[$p]], $slot[($p + 1) % $n]];
			ksort($items);
			$board = array_fill(0, $n, false);
		}
		else
		{
			// Picks size playable categories of the selected types, no two with the same parent, each keeping size items that are in no other picked category
			$cats = [];
			foreach ($mysql->execute_query("select c.id, c.name, c.parent_id, m.item, m.obscure from membership m join category c on c.id = m.category_id where c.language = ? and c.playable and find_in_set(c.type, ?)", [$_POST['language'], implode(',', $_POST['types'])]) as $r)
			{
				$cats[$r['id']] ??= ['name' => $r['name'], 'parent' => $r['parent_id'], 'all' => [], 'items' => []];
				$cats[$r['id']]['all'][$r['item']] = true;
				if (!$r['obscure']) $cats[$r['id']]['items'][] = $r['item'];
			}
			$exclusive = fn($a, $picks) => array_filter($cats[$a]['items'], fn($w) => !array_filter($picks, fn($b) => $b != $a && isset($cats[$b]['all'][$w])));
			$ids = array_keys($cats);
			shuffle($ids);
			$chosen = [];
			foreach ($ids as $c)
			{
				if (count($chosen) == $size) break;
				if ($cats[$c]['parent'] !== null && in_array($cats[$c]['parent'], array_map(fn($b) => $cats[$b]['parent'], $chosen))) continue;
				$trial = [...$chosen, $c];
				foreach ($trial as $a) if (count($exclusive($a, $trial)) < $size) continue 2;
				$chosen = $trial;
			}
			$n = count($chosen);
			$items = [];
			foreach ($chosen as $c)
			{
				$words = $exclusive($c, $chosen);
				shuffle($words);
				foreach (array_slice($words, 0, $n) as $w) $items[] = [$w, $cats[$c]['name']];
			}
			shuffle($items);
			$board = array_fill(0, $n * $n, -1);
		}
		$mysql->execute_query("insert into game (seed, language, mode, types, size, items, board, multiplayer, created_by, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, now())", [$seed, $_POST['language'], $_POST['mode'], json_encode($_POST['types']), $n, json_encode($items, JSON_UNESCAPED_UNICODE), json_encode($board), (int)isset($_POST['multiplayer']), $_SESSION['user_id']]);
		header('Location: ./?go=' . (isset($_GET['daily']) ? "daily&lang=$_GET[daily]" : "game&seed=$seed"));
		break;
	// Sorting: b's cluster is appended to a's: b's first word gets a's last one as previous; answers with the category's name when its cluster is complete.
	// Chain: clusters are runs of the cycle, two merge when one's last word links to the other's first, which sets the last word's flag; swap says b's cluster comes first.
	// Answers with the link's category, and when the chain is finished with the category of the link that closes the cycle.
	// A merge is stale when another player has merged a or b already; a mismatch is a mistake even then.
	case 'merge':
		$mysql->begin_transaction();
		$game = $mysql->execute_query("select mode, board, items, language, size, multiplayer from game where seed = ? and (multiplayer or created_by = ?) for update", [$seed, $user])->fetch_assoc();
		$board = json_decode($game['board']);
		$items = json_decode($game['items']);
		$a = (int)$_POST['a'];
		$b = (int)$_POST['b'];
		if ($game['mode'] == 'chain')
		{
			$prev = [];
			foreach ($items as $i => $w) $prev[$w[2]] = $i;
			$head = function($i) use ($board, $prev) { while ($board[$prev[$i]]) $i = $prev[$i]; return $i; };
			$tail = function($i) use ($board, $items) { while ($board[$i]) $i = $items[$i][2]; return $i; };
			$swap = $items[$tail($a)][2] != $head($b);
			$joint = $swap ? $tail($b) : $tail($a);
			$mistake = $head($a) != $head($b) && $items[$joint][2] != $head($swap ? $a : $b);
			$stale = $head($a) != $a || $head($b) != $b;
		}
		else
		{
			$category = $items[$a][1];
			$mistake = $items[$b][1] != $category;
			$stale = $board[$a] != -1 || $board[$b] != -1;
		}
		if ($mistake)
		{
			$mysql->execute_query("update player set mistakes = mistakes + 1 where seed = ? and user_id = ?", [$seed, $user]);
			$mysql->execute_query("update game set version = version + 1 where seed = ? and (multiplayer or created_by = ?)", [$seed, $user]);
			$answer = ['valid' => false];
		}
		elseif ($stale)
			$answer = ['valid' => false, 'stale' => true];
		else
		{
			if ($game['mode'] == 'chain')
			{
				$board[$joint] = true;
				$finished = count(array_filter($board)) == count($board) - 1;
				$answer = ['valid' => true, 'link' => $items[$joint][1], 'swap' => $swap, 'category' => $finished ? $items[array_search(false, $board, true)][1] : null];
			}
			else
			{
				for ($last = $a; ($i = array_search($last, $board, true)) !== false; $last = $i);
				$board[$b] = $last;
				$finished = count(array_keys($board, -1, true)) == sqrt(count($board));
				$complete = count(array_filter(array_keys($board, -1, true), fn($i) => $items[$i][1] == $category)) == 1;
				$answer = ['valid' => true, 'category' => $complete ? $category : null];
			}
			$mysql->execute_query("update game set board = ?, version = version + 1, finished_at = if(?, now(), null) where seed = ? and (multiplayer or created_by = ?)", [json_encode($board), (int)$finished, $seed, $user]);
			$mysql->execute_query("update player set score = score + 1 where seed = ? and user_id = ?", [$seed, $user]);
		}
		if ($mistake or !$stale) $mysql->execute_query("insert into attempt (seed, user_id, a, b, success, created_at) values (?, ?, ?, ?, ?, now())", [$seed, $user, $a, $b, (int)!$mistake]);
		$mysql->commit();
		// Achievements of a finished sorting game: par and distinct categories for a player who finished it alone, i.e. their single-player game
		// or a multiplayer game nobody else scored in; beating player 1 for everyone, on score - mistakes in multiplayer, on fewer mistakes
		// with both finished on a single-player seed, checked by whichever of the two finishes later
		if (($finished ?? false) and $game['mode'] == 'sorting')
		{
			$solo = "g.finished_at is not null and (not g.multiplayer and g.created_by = p.user_id or g.multiplayer and p.user_id = all (select o.user_id from player o where o.seed = g.seed and o.score > 0))";
			if ($user and $game['size'] >= 10 and ($mistakes = $mysql->execute_query("select p.mistakes from game g join player p on p.seed = g.seed and p.user_id = ? where g.seed = ? and $solo", [$user, $seed])->fetch_column()) !== false)
			{
				achieve($user, 'sg_par_' . $game['size'], $mistakes, true);
				$names = [];
				foreach ($mysql->execute_query("select g.items from game g join player p on p.seed = g.seed and p.user_id = ? where g.mode = 'sorting' and g.size >= 10 and g.language = ? and $solo", [$user, $game['language']]) as $row)
					foreach (json_decode($row['items']) as [, $c]) $names[$c] = true;
				achieve($user, 'sg_categories_' . $game['language'], count($names));
			}
			if ($game['multiplayer'])
				$winners = $mysql->execute_query("select p.user_id from player p join player k on k.seed = p.seed and k.user_id = 1
					where p.seed = ? and p.user_id not in (0, 1) and p.score - p.mistakes > k.score - k.mistakes", [$seed])->fetch_all();
			elseif ($user)
				$winners = $mysql->execute_query("select p.user_id from player p join game g on g.seed = p.seed and g.created_by = p.user_id
					join player k on k.seed = p.seed and k.user_id = 1 join game kg on kg.seed = p.seed and kg.created_by = 1
					where p.seed = ? and p.user_id not in (0, 1) and ? in (1, p.user_id) and g.finished_at is not null and kg.finished_at is not null and p.mistakes < k.mistakes", [$seed, $user])->fetch_all();
			foreach ($winners ?? [] as [$winner]) achieve($winner, 'sg_klaus', 1);
		}
		// Achievements of a finished daily: the streak of finished dailies in its language, and its size when finished without mistakes
		if (($finished ?? false) and preg_match($daily, $seed))
		{
			achieve($user, 'sg_streak', streak($user, $seed));
			if (!$mysql->execute_query("select mistakes from player where seed = ? and user_id = ?", [$seed, $user])->fetch_column()) achieve($user, 'sg_flawless', $game['size']);
		}
		echo json_encode($answer);
		break;
	// The ten best players of the seed by combinations minus mistakes, finished or not, and this player below them if further down
	case 'highscores':
		$top = [];
		foreach ($mysql->execute_query("select user_id, display_name, score, mistakes from player where seed = ? order by score - mistakes desc, score desc", [$seed])->fetch_all(MYSQLI_ASSOC) as $i => $row)
			if ($i < 10 or $row['user_id'] == $user) $top[] = ['rank' => $i + 1, 'name' => $row['display_name'], 'self' => $row['user_id'] == $user, 'score' => (int)$row['score'], 'mistakes' => (int)$row['mistakes']];
		echo json_encode($top);
		break;
	// Feedback for the end screen from this player's mistakes, with the game's log replayed in order to know the clusters at each attempt.
	// Sorting: both clusters count towards their categories, words still on their own also towards the word, and the pair when both are on their own.
	// Chain: a cluster's two open ends count, towards the categories of their unmade links and the end words.
	case 'stats':
		$game = $mysql->execute_query("select mode, items, multiplayer from game where seed = ? and (multiplayer or created_by = ?)", [$seed, $user])->fetch_assoc();
		$items = json_decode($game['items']);
		$chain = $game['mode'] == 'chain';
		$cats = array_fill_keys(array_column($items, 1), 0);
		$pairs = $tried = $prev = $log = [];
		$single = array_fill(0, count($items), true);
		$made = array_fill(0, count($items), false);
		if ($chain) foreach ($items as $i => $w) $prev[$w[2]] = $i;
		$head = function($i) use (&$made, $prev) { while ($made[$prev[$i]]) $i = $prev[$i]; return $i; };
		$tail = function($i) use (&$made, $items) { while ($made[$i]) $i = $items[$i][2]; return $i; };
		foreach ($mysql->execute_query("select user_id, a, b, success from attempt where seed = ? and (? or user_id = ?) order by id", [$seed, $game['multiplayer'], $user]) as $r)
		{
			[$a, $b] = [(int)$r['a'], (int)$r['b']];
			if ($r['user_id'] == $user) $log[] = (int)$r['success'];
			if ($r['success'])
			{
				if ($chain) $made[$items[$tail($a)][2] != $head($b) ? $tail($b) : $tail($a)] = true;
				$single[$a] = $single[$b] = false;
			}
			elseif ($r['user_id'] == $user)
			{
				foreach ([$a, $b] as $x)
				{
					if ($chain)
					{
						[$h, $t] = [$head($x), $tail($x)];
						$cats[$items[$prev[$h]][1]]++;
						$cats[$items[$t][1]]++;
						foreach (array_unique([$h, $t]) as $e) $tried[$e] = ($tried[$e] ?? 0) + 1;
					}
					else
					{
						$cats[$items[$x][1]]++;
						if ($single[$x]) $tried[$x] = ($tried[$x] ?? 0) + 1;
					}
				}
				if (!$chain and $single[$a] and $single[$b]) $pairs[min($a, $b) . ',' . max($a, $b)] = ($pairs[min($a, $b) . ',' . max($a, $b)] ?? 0) + 1;
			}
		}
		// Up to three each: pairs tried 3+ times, categories with mistakes, the categories with the fewest unless 3+ have none, words tried n/2+ times on an n×n board
		$top = fn($counts, $min) => array_slice(array_filter($counts, fn($n) => $n >= $min), 0, 3, true);
		$entries = fn($counts) => array_map(null, array_keys($counts), $counts);
		arsort($pairs);
		arsort($tried);
		arsort($cats);
		$worst = $top($cats, 1);
		asort($cats);
		$best = count(array_keys($cats, 0)) < 3 ? array_slice(array_diff_key($cats, $worst), 0, 3, true) : [];
		// For a daily's share text: this player's attempts in order as success or mistake, and their streak
		echo json_encode(['pairs' => $entries($top($pairs, 3)), 'worst' => $entries($worst), 'best' => $entries($best), 'tried' => $entries($top($tried, sqrt(count($items)) / 2)),
			'log' => $log, 'streak' => preg_match($daily, $seed) ? streak($user, $seed) : 0]);
		break;
	// One answer of the feedback view, an empty value takes it back
	case 'feedback':
		$key = [$seed, $user, $_POST['kind'], $_POST['item'], $_POST['category'], $_POST['other']];
		if ($_POST['value'] === '') $mysql->execute_query("delete from feedback where seed = ? and user_id = ? and kind = ? and item = ? and category = ? and other = ?", $key);
		else $mysql->execute_query("insert into feedback (seed, user_id, kind, item, category, other, display_name, value, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, now())
			on duplicate key update display_name = values(display_name), value = values(value), created_at = now()", [...$key, $_SESSION['display_name'], $_POST['value']]);
		break;
	case 'layout':
		$mysql->execute_query("update player set layout = ? where seed = ? and user_id = ?", [$_POST['layout'], $seed, $user]);
		break;
	case 'slots':
		$mysql->execute_query("update player set slots = ? where seed = ? and user_id = ?", [$_POST['slots'], $seed, $user]);
		break;
}

// the number of consecutive dailies in the language of daily $seed that $user finished, ending with that day's
function streak($user, $seed)
{
	global $mysql;
	[$day, $language] = [substr($seed, 0, 10), substr($seed, 11)];
	$done = array_flip(array_column($mysql->execute_query("select seed from game where created_by = ? and finished_at is not null and seed like ?", [$user, "____-__-__-$language"])->fetch_all(), 0));
	for ($n = 0; isset($done[date('Y-m-d', strtotime("$day -$n day")) . "-$language"]); $n++);
	return $n;
}

// grants the level $n reaches on $key's thresholds, at or below them if $invert; a level raised for this session's player goes into the merge's answer
function achieve($user, $key, $n, $invert = false)
{
	global $answer;
	static $catalogue = require "../achievements.php";
	$level = 0;
	foreach ($catalogue['sortinggame']['badges'][$key]['levels'] as $threshold)
		if ($invert ? $n <= $threshold : $n >= $threshold) $level++;
	if ($level and grantAchievement($user, $key, $level) and $user == $_SESSION['user_id'])
		$answer['achievements'][] = ['badge' => $key, 'level' => $level, 'description' => achievementText($catalogue['sortinggame']['badges'][$key], 'en', $level)];
}
