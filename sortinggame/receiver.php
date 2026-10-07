<?php
require_once("config.php");
require_once("../identity.php");

$seed = $_POST['seed'];
$user = $_SESSION['user_id'];
switch ($_POST['action'])
{
	// b's cluster is appended to a's: b's first word gets a's last one as previous
	case 'merge':
		$board = json_decode($mysql->execute_query("select board from game where seed = ?", [$seed])->fetch_column());
		for ($last = (int)$_POST['a']; ($i = array_search($last, $board, true)) !== false; $last = $i);
		$board[(int)$_POST['b']] = $last;
		$finished = count(array_keys($board, -1, true)) == sqrt(count($board));
		$mysql->execute_query("update game set board = ?, version = version + 1, finished_at = if(?, now(), null) where seed = ?", [json_encode($board), (int)$finished, $seed]);
		$mysql->execute_query("update player set score = score + 1 where seed = ? and user_id = ?", [$seed, $user]);
		break;
	case 'mistake':
		$mysql->execute_query("update player set mistakes = mistakes + 1 where seed = ? and user_id = ?", [$seed, $user]);
		break;
	case 'layout':
		$mysql->execute_query("update player set layout = ? where seed = ? and user_id = ?", [$_POST['layout'], $seed, $user]);
		break;
	case 'slots':
		$mysql->execute_query("update player set slots = ? where seed = ? and user_id = ?", [$_POST['slots'], $seed, $user]);
		break;
}
