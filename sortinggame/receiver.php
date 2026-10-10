<?php
require_once("config.php");
require_once("../identity.php");

$seed = $_POST['seed'] ?? bin2hex(random_bytes(4)); // new games get a random id
$user = $_SESSION['user_id'];
switch ($_POST['action'])
{
	// Picks size playable categories of the selected types, no two with the same parent, each keeping size items that are in no other picked category
	case 'create':
		identify();
		$size = (int)$_POST['size'];
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
		$mysql->execute_query("insert into game (seed, language, mode, types, size, items, board, created_by, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, now())", [$seed, $_POST['language'], $_POST['mode'], json_encode($_POST['types']), $n, json_encode($items, JSON_UNESCAPED_UNICODE), json_encode(array_fill(0, $n * $n, -1)), $_SESSION['user_id']]);
		header("Location: ./?go=game&seed=$seed");
		break;
	// b's cluster is appended to a's: b's first word gets a's last one as previous; answers with the category's name when its cluster is complete
	case 'merge':
		$game = $mysql->execute_query("select board, items from game where seed = ?", [$seed])->fetch_assoc();
		$board = json_decode($game['board']);
		$items = json_decode($game['items']);
		$a = (int)$_POST['a'];
		$b = (int)$_POST['b'];
		$category = $items[$a][1];
		if ($items[$b][1] != $category)
		{
			$mysql->execute_query("update player set mistakes = mistakes + 1 where seed = ? and user_id = ?", [$seed, $user]);
			echo json_encode(['valid' => false]);
			break;
		}
		for ($last = $a; ($i = array_search($last, $board, true)) !== false; $last = $i);
		$board[$b] = $last;
		$finished = count(array_keys($board, -1, true)) == sqrt(count($board));
		$mysql->execute_query("update game set board = ?, version = version + 1, finished_at = if(?, now(), null) where seed = ?", [json_encode($board), (int)$finished, $seed]);
		$mysql->execute_query("update player set score = score + 1 where seed = ? and user_id = ?", [$seed, $user]);
		$complete = count(array_filter(array_keys($board, -1, true), fn($i) => $items[$i][1] == $category)) == 1;
		echo json_encode(['valid' => true, 'category' => $complete ? $category : null]);
		break;
	case 'layout':
		$mysql->execute_query("update player set layout = ? where seed = ? and user_id = ?", [$_POST['layout'], $seed, $user]);
		break;
	case 'slots':
		$mysql->execute_query("update player set slots = ? where seed = ? and user_id = ?", [$_POST['slots'], $seed, $user]);
		break;
}
