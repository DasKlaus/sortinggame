<?php
require_once("config.php");
require_once("../identity.php");

$user = $_SESSION['user_id'];

// Polls of a multiplayer game: the board and the other players, unless nothing changed since the client's version
if ($_SERVER['REQUEST_METHOD'] == 'GET')
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
		$size = (int)$_POST['size'];
		if ($_POST['mode'] == 'chain')
		{
			// Randomized depth-first search for a cycle of size words, each linked to the next by a playable category of the selected types, no two with the same parent.
			// Every category, from all memberships, that holds two board words holds just the two of a link.
			// A search gives up after 1000 steps and restarts at another word; after 20 restarts the chain gets one word shorter.
			$name = $group = $of = $link = [];
			foreach ($mysql->execute_query("select c.id, c.name, c.type, c.parent_id, c.playable, m.item, m.obscure from membership m join category c on c.id = m.category_id where c.language = ?", [$_POST['language']]) as $r)
			{
				$name[$r['id']] = $r['name'];
				$group[$r['id']] = $r['parent_id'] ?? 'c' . $r['id']; // categories without a parent are a group of their own
				$of[$r['item']][$r['id']] = $r['obscure'];
				if ($r['playable'] and in_array($r['type'], $_POST['types']) and !$r['obscure']) $link[$r['id']][] = $r['item'];
			}
			$starts = array_keys(array_filter($of, fn($cs) => count(array_filter($cs, fn($obscure, $c) => !$obscure && isset($link[$c]), ARRAY_FILTER_USE_BOTH)) >= 2));
			$search = function() use (&$search, &$path, &$on, &$in, &$taken, &$picked, &$steps, &$n, $of, $link, $group)
			{
				if (++$steps > 1000) return false;
				$x = end($path);
				$moves = [];
				foreach ($of[$x] as $c => $obscure) if (!$obscure && isset($link[$c]) && !isset($taken[$group[$c]])) foreach ($link[$c] as $y) if (!isset($on[$y])) $moves[] = [$c, $y];
				shuffle($moves);
				foreach ($moves as [$c, $y])
				{
					// y may share a category with x, or with the first word when it is the last one, but with no other board word
					foreach ($of[$y] as $d => $_) if (!empty($in[$d]) && $in[$d] !== [$x] && !(count($path) == $n - 1 && $in[$d] === [$path[0]])) continue 2;
					$taken[$group[$c]] = true;
					$path[] = $y;
					$on[$y] = true;
					$picked[] = $c;
					foreach ($of[$y] as $d => $_) $in[$d][] = $y;
					if (count($path) < $n)
					{
						if ($search()) return true;
					}
					else foreach ($of[$y] as $d => $obscure) if (!$obscure && isset($link[$d]) && !isset($taken[$group[$d]]) && isset($of[$path[0]][$d]) && !$of[$path[0]][$d])
					{
						$picked[] = $d;
						return true;
					}
					unset($taken[$group[$c]], $on[$y]);
					array_pop($path);
					array_pop($picked);
					foreach ($of[$y] as $d => $_) array_pop($in[$d]);
				}
				return false;
			};
			for ($n = $size; ; $n--) for ($try = 0; $try < 20; $try++)
			{
				$x = (string)$starts[array_rand($starts)];
				[$path, $on, $in, $taken, $picked, $steps] = [[$x], [$x => true], [], [], [], 0];
				foreach ($of[$x] as $d => $_) $in[$d] = [$x];
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
		header("Location: ./?go=game&seed=$seed");
		break;
	// Sorting: b's cluster is appended to a's: b's first word gets a's last one as previous; answers with the category's name when its cluster is complete.
	// Chain: clusters are runs of the cycle, two merge when one's last word links to the other's first, which sets the last word's flag; swap says b's cluster comes first.
	// Answers with the link's category, and when the chain is finished with the category of the link that closes the cycle.
	// A merge is stale when another player has merged a or b already; a mismatch is a mistake even then.
	case 'merge':
		$mysql->begin_transaction();
		$game = $mysql->execute_query("select mode, board, items from game where seed = ? and (multiplayer or created_by = ?) for update", [$seed, $user])->fetch_assoc();
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
		$mysql->commit();
		echo json_encode($answer);
		break;
	case 'layout':
		$mysql->execute_query("update player set layout = ? where seed = ? and user_id = ?", [$_POST['layout'], $seed, $user]);
		break;
	case 'slots':
		$mysql->execute_query("update player set slots = ? where seed = ? and user_id = ?", [$_POST['slots'], $seed, $user]);
		break;
}
