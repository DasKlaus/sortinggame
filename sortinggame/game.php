<?php
if (isset($_GET["seed"])) {
	$game_lang = substr($_GET["seed"],0,2);
?>
<nav>
	Combine items two at a time into categories. Score:&nbsp;<span id="score">0</span> Mistakes:&nbsp;<span id="mistakes">0</span>
	<select id="layout" onchange="setLayout(this.value)">
		<option value="grid">Grid</option><option value="wrap">Wrap</option><option value="tight">Tight</option>
	</select>
	<button type="button" onclick="reflow()">Reflow</button>
	<button type="button" onclick="order()">Order</button>
</nav>
<div id="board"></div>
<script src="<?php echo $game_lang ?>.js"></script>
<?php
}
else {
?>
<div id="buttons">
	<label for="size">Size: </label><select id="size"><option value="5">5x5</option><option value="10">10x10</option><option value="15">15x15</option><option value="20">20x20</option><option value="25">25x25</option></select>
	<label for="lang">Language: </label><select id="lang"><option value="en">en</option><option value="de">de</option></select>&nbsp;
	<button type="button" onclick="generatepuzzle(document.getElementById('size').value)">Generate</button>
</div>
<?php
}
?>
<script src="board.js"></script>
<script src="game.js"></script>