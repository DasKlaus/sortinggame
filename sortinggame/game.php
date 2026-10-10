<?php
if (isset($_GET["seed"])) {
	$seed = $_GET["seed"];
	[$multiplayer, $size] = $mysql->execute_query("select multiplayer, size from game where seed = ? limit 1", [$seed])->fetch_row();
	if ($multiplayer and !$_SESSION['user_id']) {
		echo '<p>This is a multiplayer game. Pick a name to join in, or leave the field empty to play anonymously. If you have an authentication code, you can sign in with it.</p>';
		identityForm('en');
		return;
	}
	identify();
	// Every player of a single-player board gets their own game of it, with a fresh board
	if (!$multiplayer) $mysql->execute_query("insert ignore into game (seed, language, mode, types, size, items, board, created_by, created_at) select seed, language, mode, types, size, items, ?, ?, now() from game where seed = ? limit 1", [json_encode(array_fill(0, $size * $size, -1)), $_SESSION['user_id'], $seed]);
	$mysql->execute_query("insert ignore into player (seed, user_id, display_name) values (?, ?, ?)", [$seed, $_SESSION['user_id'], $_SESSION['display_name']]);
	$state = $mysql->execute_query("select g.seed, g.items, g.board, g.multiplayer, p.score, p.mistakes, p.layout, p.slots from game g join player p on p.seed = g.seed and p.user_id = ? where g.seed = ? and (g.multiplayer or g.created_by = ?)", [$_SESSION['user_id'], $seed, $_SESSION['user_id']])->fetch_assoc();
	$items = json_decode($state['items']);
	unset($state['items']);
	$state['words'] = array_column($items, 0);
	$state['board'] = json_decode($state['board']);
	$state['slots'] = json_decode($state['slots'] ?? 'null');
	// A category is complete when only one of its words starts a cluster
	$heads = array_keys($state['board'], -1, true);
	$count = array_count_values(array_map(fn($i) => $items[$i][1], $heads));
	$state['done'] = (object)[];
	foreach ($heads as $i) if ($count[$items[$i][1]] == 1) $state['done']->$i = $items[$i][1];
?>
<nav>
	Combine items two at a time into categories. Score:&nbsp;<span id="score"><?php echo $state['score'] ?></span> Mistakes:&nbsp;<span id="mistakes"><?php echo $state['mistakes'] ?></span>
<?php if ($multiplayer) { ?>
	Others (score/mistakes):&nbsp;<span id="players"></span>
<?php } ?>
	<select id="layout" onchange="setLayout(this.value)">
<?php foreach (['grid' => 'Grid', 'wrap' => 'Wrap', 'tight' => 'Tight'] as $value => $label) { ?>
		<option value="<?php echo $value ?>"<?php echo $value == $state['layout'] ? ' selected' : '' ?>><?php echo $label ?></option>
<?php } ?>
	</select>
	<button type="button" onclick="reflow()">Reflow</button>
	<button type="button" onclick="order()">Order</button>
</nav>
<div id="board" class="<?php echo $state['layout'] ?>"></div>
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
	<label for="size">Size: </label><select id="size" name="size"><option value="5">5x5</option><option value="10">10x10</option><option value="15">15x15</option><option value="20">20x20</option><option value="25">25x25</option></select>
	<label for="lang">Language: </label><select id="lang" name="language"><option value="en">en</option><option value="de">de</option></select>
	<label for="mode">Mode: </label><select id="mode" name="mode"><option value="sorting">Sorting</option></select>
	<label><input type="checkbox" name="multiplayer" value="1">Multiplayer</label>
	<br>
<?php foreach (['compound', 'rhyme', 'anagram', 'meaning', 'origin', 'spelling'] as $type) { ?>
	<label><input type="checkbox" name="types[]" value="<?php echo $type ?>" checked><?php echo $type ?></label>
<?php } ?>
	<button type="submit">Generate</button>
</form>
<?php
}
?>
