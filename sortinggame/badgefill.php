<?php
header('Content-Type: text/plain');
require_once("config.php");
require_once("../identity.php");
$catalogue = require "../achievements.php";

// Sets every player's level of each of this project's achievements from history, as receiver.php grants them live.
// Events are [user, n, time]: a player holds the highest level any of their events reaches, granted when it was first reached;
// par counts at or below its thresholds. A row this changes is marked seen; one it leaves alone keeps its seen.

// a finished sorting game of at least 10×10 with the player who finished it alone: their single-player game, or a multiplayer game nobody else scored in
const SOLO = "select g.language, g.size, g.items, g.finished_at, p.user_id, p.mistakes from game g join player p on p.seed = g.seed and p.user_id > 0
	where g.mode = 'sorting' and g.size >= 10 and g.finished_at is not null
	and (not g.multiplayer and g.created_by = p.user_id or g.multiplayer and p.user_id = all (select o.user_id from player o where o.seed = g.seed and o.score > 0))";

$events = $invert = [];
// distinct categories, each counted when first finished
$names = [];
foreach ($mysql->execute_query(SOLO." order by g.finished_at") as $row)
{
	$events['sg_par_'.$row['size']][] = [$row['user_id'], $row['mistakes'], $row['finished_at']];
	$invert['sg_par_'.$row['size']] = true;
	foreach (json_decode($row['items']) as [, $c])
		if (!isset($names[$row['user_id']][$row['language']][$c]))
		{
			$names[$row['user_id']][$row['language']][$c] = true;
			$events['sg_categories_'.$row['language']][] = [$row['user_id'], count($names[$row['user_id']][$row['language']]), $row['finished_at']];
		}
}
// multiplayer on score - mistakes at the game's finish, single-player on fewer mistakes once both have finished
$events['sg_klaus'] = $mysql->execute_query("select p.user_id, 1, g.finished_at from game g join player p on p.seed = g.seed join player k on k.seed = g.seed and k.user_id = 1
		where g.multiplayer and g.mode = 'sorting' and g.finished_at is not null and p.user_id not in (0, 1) and p.score - p.mistakes > k.score - k.mistakes
	union all select p.user_id, 1, greatest(g.finished_at, kg.finished_at) from game g join player p on p.seed = g.seed and p.user_id = g.created_by
		join player k on k.seed = g.seed and k.user_id = 1 join game kg on kg.seed = g.seed and kg.created_by = 1
		where not g.multiplayer and g.mode = 'sorting' and g.finished_at is not null and kg.finished_at is not null and p.user_id not in (0, 1) and p.mistakes < k.mistakes
	order by 3")->fetch_all();
// finished dailies: the run of consecutive days in a language each reaches, and the size of those without mistakes
$run = [];
foreach ($mysql->execute_query("select g.seed, g.size, g.finished_at, p.user_id, p.mistakes from game g join player p on p.seed = g.seed and p.user_id = g.created_by
	where g.seed regexp '^[0-9]{4}-[0-9]{2}-[0-9]{2}-' and g.finished_at is not null and p.user_id > 0 order by g.seed") as $row)
{
	[$day, $language] = [substr($row['seed'], 0, 10), substr($row['seed'], 11)];
	$r = &$run[$row['user_id']][$language];
	$r = ($r[0] ?? '') == date('Y-m-d', strtotime("$day -1 day")) ? [$day, $r[1] + 1] : [$day, 1];
	$events['sg_streak'][] = [$row['user_id'], $r[1], $row['finished_at']];
	unset($r);
	if (!$row['mistakes']) $events['sg_flawless'][] = [$row['user_id'], $row['size'], $row['finished_at']];
}

identityConnect();
foreach ($catalogue['sortinggame']['badges'] as $key => $entry)
{
	$held = [];
	foreach ($events[$key] ?? [] as [$user, $n, $at])
	{
		$level = 0;
		foreach ($entry['levels'] as $threshold)
			if (isset($invert[$key]) ? $n <= $threshold : $n >= $threshold) $level++;
		if ($level > ($held[$user][0] ?? 0)) $held[$user] = [$level, $at];
	}
	$current = array_column($identity->execute_query("select user_id, level from achievement where badge = ?", [$key])->fetch_all(), 1, 0);
	$changed = 0;
	foreach ($current as $user => $level)
		if (!isset($held[$user]))
		{
			$identity->execute_query("delete from achievement where user_id = ? and badge = ?", [$user, $key]);
			$changed++;
		}
	foreach ($held as $user => [$level, $at])
		if (($current[$user] ?? 0) != $level)
		{
			$identity->execute_query("insert into achievement (user_id, badge, level, granted_at, seen) values (?, ?, ?, ?, 1)
				on duplicate key update level = values(level), granted_at = values(granted_at), seen = 1", [$user, $key, $level, $at]);
			$changed++;
		}
	echo $key.": ".count($held)." players, ".$changed." changed".PHP_EOL;
}

echo "Done!".PHP_EOL;
