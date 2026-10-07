<?php
if (isset($_GET["seed"])) {
	identify();
	$seed = $_GET["seed"];
	preg_match('/^([a-z]+)(\d+)-/', $seed, $m);
	[, $language, $size] = $m;
	$mysql->execute_query("insert ignore into game (seed, language, size, board, created_by, created_at) values (?, ?, ?, ?, ?, now())", [$seed, $language, $size, json_encode(array_fill(0, $size * $size, -1)), $_SESSION['user_id']]);
	$mysql->execute_query("insert ignore into player (seed, user_id, display_name) values (?, ?, ?)", [$seed, $_SESSION['user_id'], $_SESSION['display_name']]);
	$state = $mysql->execute_query("select g.seed, g.size, g.board, p.score, p.mistakes, p.layout, p.slots from game g join player p on p.seed = g.seed and p.user_id = ? where g.seed = ?", [$_SESSION['user_id'], $seed])->fetch_assoc();
	$state['board'] = json_decode($state['board']);
	$state['slots'] = json_decode($state['slots'] ?? 'null');
?>
<nav>
	Combine items two at a time into categories. Score:&nbsp;<span id="score"><?php echo $state['score'] ?></span> Mistakes:&nbsp;<span id="mistakes"><?php echo $state['mistakes'] ?></span>
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
<script src="<?php echo $language ?>.js"></script>
<script src="board.js"></script>
<script src="game.js"></script>
<?php
}
else {
?>
<div id="buttons">
	<label for="size">Size: </label><select id="size"><option value="5">5x5</option><option value="10">10x10</option><option value="15">15x15</option><option value="20">20x20</option><option value="25">25x25</option></select>
	<label for="lang">Language: </label><select id="lang"><option value="en">en</option><option value="de">de</option></select>&nbsp;
	<button type="button" onclick="location.search = '?go=game&seed=' + document.getElementById('lang').value + document.getElementById('size').value + '-' + Math.random().toString(36).slice(2, 7)">Generate</button>
</div>
<?php
}
?>
