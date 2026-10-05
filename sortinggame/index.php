<?php
if (($_GET['go'] ?? '') == 'game') {
    include 'game.php';
    exit;
}
?>
<button onclick="location = '?go=game'">To the game</button>
<?php
require_once("../legal.php");
legalNotice('en');
