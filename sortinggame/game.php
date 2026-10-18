<?php
if (isset($_GET["seed"])) {
	$seed = $_GET["seed"];
	[$multiplayer, $mode, $size, $language, $types] = $mysql->execute_query("select multiplayer, mode, size, language, types from game where seed = ? limit 1", [$seed])->fetch_row();
	if ($multiplayer and !$_SESSION['user_id']) {
		echo '<p>This is a multiplayer game. Pick a name to join in, or leave the field empty to play anonymously. If you have an authentication code, you can sign in with it.</p>';
		identityForm('en');
		return;
	}
	identify();
	// Every player of a single-player board gets their own game of it, with a fresh board
	if (!$multiplayer) $mysql->execute_query("insert ignore into game (seed, language, mode, types, size, items, board, created_by, created_at) select seed, language, mode, types, size, items, ?, ?, now() from game where seed = ? limit 1", [json_encode($mode == 'chain' ? array_fill(0, $size, false) : array_fill(0, $size * $size, -1)), $_SESSION['user_id'], $seed]);
	$mysql->execute_query("insert ignore into player (seed, user_id, display_name, layout) values (?, ?, ?, ?)", [$seed, $_SESSION['user_id'], $_SESSION['display_name'], $size >= 10 ? 'wrap' : 'grid']);
	$state = $mysql->execute_query("select g.seed, g.mode, g.items, g.board, g.multiplayer, p.score, p.mistakes, p.layout, p.slots from game g join player p on p.seed = g.seed and p.user_id = ? where g.seed = ? and (g.multiplayer or g.created_by = ?)", [$_SESSION['user_id'], $seed, $_SESSION['user_id']])->fetch_assoc();
	$items = json_decode($state['items']);
	unset($state['items']);
	$state['words'] = array_column($items, 0);
	$state['board'] = json_decode($state['board']);
	$state['slots'] = json_decode($state['slots'] ?? 'null');
	$state['done'] = (object)[];
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
	<button type="button" onclick="openPopout(this.title + '<p>Layout (dropdown in the top bar): <b>Grid</b> shows the board as a square, which gets tiny on small screens. <b>Wrap</b> fills the screen width and scrolls down, best on phones. <b>Tight</b> packs the words in one line each, as narrow as possible. &quot;N lines&quot; limits how many lines of a long word are shown, &quot;Reflow&quot; fills gaps when items merge, and &quot;Order&quot; moves the biggest clusters to the front.</p>')" title="<?php echo $mode == 'chain' ? 'Link the words two at a time into one closed chain.' : 'Combine items two at a time into categories.' ?>">?</button> Combinations:&nbsp;<span id="score"><?php echo $state['score'] ?></span> Mistakes:&nbsp;<span id="mistakes"><?php echo $state['mistakes'] ?></span>
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
	if (isset($_GET['guest']) and !$_SESSION['user_id']) {
		echo '<p>Pick a name to start a multiplayer game, or leave the field empty to play anonymously. If you have an authentication code, you can sign in with it.</p>';
		identityForm('en');
	}
?>
<form id="buttons" method="post" action="receiver.php">
	<input type="hidden" name="action" value="create">
	<label for="size">Size: </label><select id="size" name="size"><option value="5">5x5</option><option value="10">10x10</option><option value="15">15x15</option><option value="20">20x20</option><option value="25">25x25</option></select><select id="words" name="size" hidden disabled><?php foreach ([16, 25, 36, 49, 64, 81, 100] as $n) echo "<option value=\"$n\">$n words</option>"; ?></select>
	<label for="lang">Language: </label><select id="lang" name="language"><option value="en">en</option><option value="de">de</option></select>
	<label for="mode">Mode: </label><select id="mode" name="mode"><option value="sorting">Sorting</option><option value="chain">Chain</option></select>
	<label><input type="checkbox" name="multiplayer" value="1">Multiplayer</label>
	<br>
<?php $types = $mysql->query("select distinct type from category order by type")->fetch_all(); ?>
	<label for="types">Category types: </label><select id="types" name="types[]" multiple size="<?php echo count($types) ?>">
<?php foreach ($types as [$type]) { ?>
		<option value="<?php echo $type ?>" selected><?php echo $type ?></option>
<?php } ?>
	</select>
	<small>Ctrl-click to pick several</small>
	<button type="submit">Generate</button>
</form>
<script>
// A chain's size is its number of words, and it needs at least three category types
const form = document.getElementById('buttons');
form.mode.onchange = () => form.querySelectorAll('[name=size]').forEach(s => s.hidden = s.disabled = (s.id == 'words') != (form.mode.value == 'chain'));
form.onsubmit = () => form.mode.value != 'chain' || form['types[]'].selectedOptions.length >= 3 || (alert('A chain needs at least three category types.'), false);
</script>
<?php
}
?>
