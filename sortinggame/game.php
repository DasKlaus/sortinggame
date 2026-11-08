<?php
if (isset($_GET["seed"])) {
	$seed = $_GET["seed"];
	[$multiplayer, $mode, $size, $language, $types, $levels] = $mysql->execute_query("select multiplayer, mode, size, language, types, levels from game where seed = ? limit 1", [$seed])->fetch_row();
	if ($multiplayer and !$_SESSION['user_id']) {
		echo '<p>This is a multiplayer game. Pick a name to join in, or leave the field empty to play anonymously. If you have an authentication code, you can sign in with it.</p>';
		identityForm('en');
		return;
	}
	identify();
	// Every player of a single-player board gets their own game of it, with a fresh board
	if (!$multiplayer) $mysql->execute_query("insert ignore into game (seed, language, mode, types, levels, size, items, board, created_by, created_at) select seed, language, mode, types, levels, size, items, ?, ?, now() from game where seed = ? limit 1", [json_encode($mode == 'chain' ? array_fill(0, $size, false) : array_fill(0, $size * $size, -1)), $_SESSION['user_id'], $seed]);
	$mysql->execute_query("insert ignore into player (seed, user_id, display_name, layout) values (?, ?, ?, ?)", [$seed, $_SESSION['user_id'], $_SESSION['display_name'], $size >= 10 ? 'wrap' : 'grid']);
	$state = $mysql->execute_query("select g.seed, g.mode, g.items, g.board, g.multiplayer, p.score, p.mistakes, p.layout, p.slots from game g join player p on p.seed = g.seed and p.user_id = ? where g.seed = ? and (g.multiplayer or g.created_by = ?)", [$_SESSION['user_id'], $seed, $_SESSION['user_id']])->fetch_assoc();
	$items = json_decode($state['items']);
	unset($state['items']);
	$state['words'] = array_column($items, 0);
	$state['board'] = json_decode($state['board']);
	$state['slots'] = json_decode($state['slots'] ?? 'null');
	$state['done'] = (object)[];
	// A daily's number counts from the first daily, which is number 1
	if (preg_match('/^\d{4}-\d\d-\d\d-/', $seed)) $state['daily'] = ['language' => $language, 'number' => 1 + round((strtotime(substr($seed, 0, 10)) - strtotime('2026-10-24')) / 86400)];
	if ($mode == 'chain')
	{
		// The client gets each word's previous one in its cluster and the link's category; a finished chain is done with the category that closes the cycle
		$flags = $state['board'];
		$state['board'] = array_fill(0, count($items), -1);
		$state['links'] = array_fill(0, count($items), null);
		foreach ($flags as $i => $made) if ($made) [$state['board'][$items[$i][2]], $state['links'][$items[$i][2]]] = [$i, $items[$i][1]];
		if (count(array_filter($flags)) == count($flags) - 1)
		{
			$u = array_search(false, $flags, true);
			$state['done']->{$items[$u][2]} = $items[$u][1];
		}
	}
	else
	{
		// A category is complete when only one of its words starts a cluster
		$heads = array_keys($state['board'], -1, true);
		$count = array_count_values(array_map(fn($i) => $items[$i][1], $heads));
		foreach ($heads as $i) if ($count[$items[$i][1]] == 1) $state['done']->$i = $items[$i][1];
	}
?>
<nav>
	<button type="button" onclick="openPopout('<p>' + this.title + '</p><p>Layout (dropdown in the header): <br>- <b>Grid</b> shows the board as a square, which gets tiny on small screens. <br>- <b>Wrap</b> fills the screen width and scrolls down, best on phones. <br>- <b>Tight</b> packs the words in one line each, as narrow as possible. <br>&quot;N lines&quot; limits how many lines of a long word are shown, <br>&quot;Reflow&quot; fills gaps when items merge, and <br>&quot;Order&quot; moves the biggest clusters to the front.</p>')" title="<?php echo $mode == 'chain' ? 'Link the words two at a time into one closed chain, clicking one after the other. Every item has exactly two neighbours to which it is linked in a unique way.' : 'Combine items two at a time into categories, clicking one after the other.' ?>">?</button> Combinations:&nbsp;<span id="score"><?php echo $state['score'] ?></span> Mistakes:&nbsp;<span id="mistakes"><?php echo $state['mistakes'] ?></span>
<?php if ($multiplayer) { ?>
	Others (combinations/mistakes):&nbsp;<span id="players"></span>
<?php } ?>
	<select id="layout" onchange="setLayout(this.value)">
<?php foreach (['grid' => 'Grid', 'wrap' => 'Wrap', 'tight' => 'Tight'] as $value => $label) { ?>
		<option value="<?php echo $value ?>"<?php echo $value == $state['layout'] ? ' selected' : '' ?>><?php echo $label ?></option>
<?php } ?>
	</select>
	<select id="lines" onchange="document.getElementById('board').style.setProperty('--lines', this.value)">
<?php for ($n = 1; $n <= 10; $n++) { ?>
		<option value="<?php echo $n ?>"<?php echo $n == 4 ? ' selected' : '' ?>><?php echo $n == 1 ? '1 line' : "$n lines" ?></option>
<?php } ?>
	</select>
	<label><input type="checkbox" id="reflow" onchange="if (this.checked) reflow()">Reflow</label>
	<button type="button" onclick="order()">Order</button>
</nav>
<div id="board" class="<?php echo $state['layout'] ?>"></div>
<form id="again" method="post" action="receiver.php" hidden>
	<input type="hidden" name="action" value="create">
	<input type="hidden" name="language" value="<?php echo $language ?>">
	<input type="hidden" name="mode" value="<?php echo $mode ?>">
	<input type="hidden" name="size" value="<?php echo $size ?>">
<?php foreach (json_decode($types) as $type) { ?>
	<input type="hidden" name="types[]" value="<?php echo htmlspecialchars($type) ?>">
<?php } ?>
<?php // games from before topics play again with every topic at medium
	foreach (json_decode($levels ?? 'null', true) ?? array_fill_keys(array_column($mysql->query("select distinct topic from category")->fetch_all(), 0), 2) as $topic => $level) { ?>
	<input type="hidden" name="levels[<?php echo htmlspecialchars($topic) ?>]" value="<?php echo $level ?>">
<?php } ?>
<?php if ($multiplayer) { ?>
	<input type="hidden" name="multiplayer" value="1">
<?php } ?>
</form>
<script>var state = <?php echo json_encode($state) ?>;</script>
<script src="board.js"></script>
<script src="game.js"></script>
<?php
}
else {
	echo '<div id="daily">Play today\'s chain link game: <button type="button" onclick="location = \'?go=daily&lang=de\'">de</button> <button type="button" onclick="location = \'?go=daily&lang=en\'">en</button></div>';
	if (isset($_GET['guest']) and !$_SESSION['user_id']) {
		echo '<p>Pick a name to start a multiplayer game, or leave the field empty to play anonymously. If you have an authentication code, you can sign in with it.</p>';
		identityForm('en');
	}
?>
<div id="play">
<?php
	// This player's games without dailies, newest first: unfinished ones with the board's progress, linking to the game;
	// the last 25 finished sorting games with mistakes and no feedback yet with their score, linking to the feedback view
	if ($_SESSION['user_id']) foreach ([['Unfinished games', 'g.finished_at is null', 'game'],
		['Add feedback to finished games', "g.finished_at is not null and g.mode = 'sorting' and p.mistakes > 0 and not exists (select 1 from feedback f where f.seed = g.seed and f.user_id = p.user_id)", 'feedback']] as [$label, $where, $go]) {
?>
<details<?php echo $go == 'game' ? ' open' : '' ?>><summary><?php echo $label ?></summary>
<?php foreach ($mysql->execute_query("select g.seed, date_format(g.created_at, '%d.%m.%Y %H:%i') created, g.size, g.language, g.board, p.score - p.mistakes total from game g join player p on p.seed = g.seed and p.user_id = ?
		where (g.multiplayer or g.created_by = p.user_id) and g.seed not like '____-__-__-%' and $where order by g.created_at desc" . ($go == 'game' ? '' : ' limit 25'), [$_SESSION['user_id']]) as $g) { ?>
	<a class="game" href="?go=<?php echo $go ?>&amp;seed=<?php echo $g['seed'] ?>"><span><?php echo $g['created'] ?></span><span><?php echo "$g[size]×$g[size]" ?></span><span><?php echo $g['language'] ?></span><span><?php echo $go == 'game' ? ($g['size'] < 2 ? 100 : floor(100 * count(array_diff(json_decode($g['board']), [-1])) / ($g['size'] * ($g['size'] - 1)))) . '%' : $g['total'] ?></span></a>
<?php } ?>
</details>
<?php } ?>
<h3>New game</h3>
<?php
	// The form starts with the settings of the last game this player created, not counting dailies or copies of others' single-player boards; topics without a level are medium
	$last = $mysql->execute_query("select size, language, multiplayer, types, levels from game g where created_by = ? and seed not like '____-__-__-%'
		and not exists (select 1 from game o where o.seed = g.seed and o.created_at < g.created_at) order by created_at desc limit 1", [$_SESSION['user_id']])->fetch_assoc();
	$lastTypes = json_decode($last['types'] ?? 'null');
	$lastLevels = json_decode($last['levels'] ?? 'null', true);
?>
<form id="buttons" method="post" action="receiver.php">
	<input type="hidden" name="action" value="create">
	<label for="size">Size: </label><select id="size" name="size"><?php foreach ([5, 10, 15, 20, 25] as $n) echo "<option value=\"$n\"" . ($n == ($last['size'] ?? 5) ? ' selected' : '') . ">{$n}x$n</option>" ?></select>
	<label for="lang">Language: </label><select id="lang" name="language"><?php foreach (['en', 'de'] as $l) echo "<option value=\"$l\"" . ($l == ($last['language'] ?? 'en') ? ' selected' : '') . ">$l</option>" ?></select>
	<label><input type="checkbox" name="multiplayer" value="1"<?php echo ($last['multiplayer'] ?? 0) ? ' checked' : '' ?>>Multiplayer</label>
	<details><summary>Restrict game categories</summary>
		<div id="types">
<?php foreach ($mysql->query("select distinct type from category order by type")->fetch_all() as [$type]) { $on = !$lastTypes || in_array($type, $lastTypes); ?>
			<button type="button"<?php echo $on ? ' class="on"' : '' ?> onclick="this.firstChild.disabled = !this.classList.toggle('on')"><input type="hidden" name="types[]" value="<?php echo htmlspecialchars($type) ?>"<?php echo $on ? '' : ' disabled' ?>><?php echo htmlspecialchars($type) ?></button>
<?php } ?>
		</div>
	</details>
	<details><summary>Topics and difficulty</summary>
		<div id="levels">Click to change difficulty: <span data-level="0">disabled</span> <span data-level="1">easy</span> <span data-level="2">medium</span> <span data-level="3" style="--hue: 200">hard</span></div>
		<div id="topics">
<?php foreach ($mysql->query("select distinct topic from category order by topic")->fetch_all() as [$topic]) { $level = $lastLevels[$topic] ?? 2; ?>
			<button type="button" data-level="<?php echo $level ?>" style="--hue: <?php echo crc32($topic) % 360 ?>" onclick="this.dataset.level = this.firstChild.value = (+this.firstChild.value + 1) % 4"><input type="hidden" name="levels[<?php echo htmlspecialchars($topic) ?>]" value="<?php echo $level ?>"><?php echo htmlspecialchars($topic) ?></button>
<?php } ?>
		</div>
	</details>
	<button type="submit">Generate</button>
</form>
</div>
<?php
}
?>
