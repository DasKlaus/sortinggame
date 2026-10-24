<?php
require_once("config.php");
require_once("../identity.php");

if (($_POST['do'] ?? '') == 'renameall') $mysql->execute_query("update player set display_name = ? where user_id = ?", [$_SESSION['display_name'], $_SESSION['user_id']]);

$go = $_GET['go'] ?? 'start';
// Today's daily in a language, created by receiver.php if nobody has played it yet
if ($go == 'daily')
{
	$_GET['seed'] = date('Y-m-d') . '-' . $_GET['lang'];
	if (!$mysql->execute_query("select 1 from game where seed = ? limit 1", [$_GET['seed']])->fetch_row())
	{
		header("Location: receiver.php?daily=$_GET[lang]");
		exit;
	}
	$go = 'game';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
	<title>The Sorting Game</title>
	<link rel="stylesheet" href="style.css">
	<meta name="description" content="Combine items two at a time into categories">
	<meta name="robots" content="<?php echo $go == 'game' ? 'noindex,nofollow' : 'index,nofollow'; ?>">
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta property="og:type" content="website">
	<meta property="og:locale" content="en_GB">
	<meta property="og:title" content="The Sorting Game">
	<meta property="og:description" content="Combine items two at a time into categories">
	<link rel="icon" href="favicon.svg">
</head>
<body>
<nav><a href="?go=profile">Profile<?php if ($_SESSION['user_id']) echo ': '.htmlspecialchars($_SESSION['display_name'] ?: 'Guest', ENT_QUOTES, 'UTF-8'); ?></a><?php if ($_SESSION['user_id']) echo '<a href="?go=achievements">Achievements</a>'; ?><a href="?go=game">Play</a><a href="?go=instructions">Instructions</a><a href="?go=impressum">Site notice</a></nav>

<?php
identityMessage('en');
if ($go == 'game') include 'game.php';
elseif ($go == 'profile') {
	echo '<h2>Profile</h2>';
	identityForm('en');
}
elseif ($go == 'achievements') {
	showAchievements('en', 'sortinggame');
	confirmAllAchievements();
}
elseif ($go == 'instructions') echo '<h2>to be done</h2>';
elseif ($go == 'impressum') {
	echo '<h2>Code</h2><p><b>Thomas Colthurst</b> created the <a href="https://thomaswc.com/2025.html">original</a>.<br>' .
		 '<b>Victoria Roberts</b> adapted <a href="https://victoria.danclan.com/the-sorting-game.html">the version</a> this one is copied from.<br>' .
		 '<b>I</b> made this largely vibecoded version, the code is available on <a href="https://github.com/DasKlaus/sortinggame">github</a>.<br>' . 
		 'The game is released under a <a href="https://creativecommons.org/licenses/by-sa/4.0/">Creative Commons Share Alike with Attribution License</a>.</p>';
	echo '<h2>Categories</h2>The categories are all new and mainly derived from <a href="https://www.wiktionary.org/">Wiktionary</a>, extracted by <a href="https://kaikki.org/">kaikki.org</a>, parsed and curated by Claude. They are therefore dominated by wordplay and thematic sets.';
	require_once("../legal.php");
	legalNotice('en');
}
else {
?>

<div id="instructions">Combine items two at a time into categories. Adapted from <a href="https://victoria.danclan.com/the-sorting-game.html">Victoria Roberts' version</a> of <a href="https://thomaswc.com/2025.html">Thomas Colthurst's original</a>.</div>
<h1><span>The</span><span>Sorting</span><span>Game</span></h1>
<div id="board" class="grid start"></div>
<div id="done" hidden><button onclick="newBoard()">Play again</button> <button onclick="location = '?go=game'">Ready for the real game</button></div>

<script src="board.js"></script>
<script>
// Categories for each title word, as [name, items]; one category per word is picked
var words = [
  [
    ["describes “the”", ["article", "three letter", "reverse alphabetic order", "closed-class word", "top 100 most common words", "Germanic root", "multiple pronunciations", "function word", "contains a digraph", "no plural", "monosyllabic", "no descenders", "no common rhyme"]],
    ["…the", ["absin", "ba", "clo", "brea", "li", "la", "scy", "ti", "shea", "wri"]],
    ["the…", ["atric", "bes", "ft", "ir", "ism", "m", "me", "matic", "n", "nce", "ocracy", "orem", "oretic", "ory", "rapy", "re", "rmal", "rmite", "rmic", "ropod", "saurus", "sis", "ta", "y"]],
    ["The … (band)", ["Beatles", "Who", "Doors", "Clash", "Kinks", "Smiths", "Cure", "Strokes", "Killers", "Ramones", "Police", "Byrds", "Pixies", "Supremes", "Monkees", "Stooges", "Animals", "Hollies", "Specials", "Pogues", "Pretenders", "Shins", "White Stripes", "Black Keys", "Rolling Stones", "Beach Boys", "Velvet Underground", "Zombies", "Temptations", "Jam", "Verve", "Libertines", "Hives", "Stone Roses", "Prodigy", "Fugees", "Roots", "Offspring", "Cranberries", "Kooks", "Lumineers", "xx", "1975", "Band", "Carpenters", "Bangles", "Ronettes", "Undertones", "Damned", "Buzzcocks"]],
    ["… the …", ["Great", "Terrible", "Bold", "Conqueror", "Lionheart", "Magnificent", "Pious", "Unready", "Confessor", "Impaler", "Fat", "Bald", "Elder", "Younger", "Wise", "Just", "Good", "Mad", "Fair", "Hammer", "Navigator", "Lawgiver", "Simple", "Fearless", "Cruel", "Brave", "Red", "Stammerer", "Venerable", "Silent", "Peaceful", "Lion", "Well-Beloved"]],
    ["“the” abroad", ["der", "die", "das", "le", "les", "el", "los", "las", "il", "lo", "gli", "het", "de", "den", "det", "o", "os", "ho", "al", "ha", "an", "yr", "az", "els", "hinn"]],
    ["… the …", ["mother", "brother", "father", "other", "either", "neither", "whether", "rather", "together", "feather", "leather", "weather", "heather", "panther", "anthem", "methane", "pathetic", "synthetic", "aesthetic", "hypothesis", "Athena", "Athens", "Netherlands", "smithereens", "otherwise", "northern", "southern", "ethereal", "Bethlehem", "atheist", "mathematics", "withered", "gathered", "bothered", "anesthetic", "prosthetic", "apothecary", "Ethernet"]],
  ],
  [
    ["means “sorting”", ["ordering", "cleaning", "classifying", "categorizing", "grading", "ranking", "arranging", "systematizing", "indexing", "separating", "sifting", "segregating", "clustering", "shelving", "winnowing", "grouping", "organizing", "placing", "compartmentalizing", "clumping", "typing", "distinguishing"]],
    ["in “sorting”", ["gin", "gist", "git", "got", "grin", "grist", "grit", "groin", "ingot", "into", "intro", "ion", "iron", "nit", "nitro", "nog", "noir", "nori", "not", "rig", "ring", "riot", "rosin", "rot", "sign", "sing", "sir", "sit", "snog", "snort", "snot", "song", "sting", "stir", "storing", "string", "strong", "tin", "ting", "tong", "ton", "torn", "trio"]],
    ["… sort", ["bubble", "quick", "merge", "heap", "insertion", "selection", "radix", "bucket", "shell", "counting", "bogo", "tim", "cocktail", "gnome", "comb", "pancake", "sleep", "stooge", "patience", "cycle", "intro", "odd-even", "strand", "smooth", "tree", "library", "bitonic", "spaghetti", "block", "pigeonhole", "flash", "topological", "tournament", "burst", "bead", "slow"]],
    ["sorting…", ["hat", "office", "algorithm", "machine", "facility", "network", "tray", "key", "box", "line", "table", "station", "plant", "hall", "bin", "room", "belt", "criterion", "function", "method", "rule", "test", "game", "conveyor", "process", "routine"]],
    ["sort orders", ["alphabetical", "chronological", "ascending", "descending", "lexicographic", "numeric", "natural", "random", "by size", "by colour", "by price", "by date", "by name", "by relevance", "by popularity", "by rating", "by distance", "by length", "by weight", "alphanumeric", "case-insensitive", "newest first", "oldest first", "A–Z", "Z–A", "reverse"]],
  ],
  [
    ["game…", ["over", "changing", "theory", "boy", "animal", "clock", "console", "design", "manual", "preserve", "season", "strategy", "tester", "play", "the system", "of chance", "of chicken", "of luck", "tree size", "is up"]],
    ["…game", ["end", "war", "to be", "adventure", "away", "home", "board", "browser", "ball", "card", "center", "guessing", "league", "memory", "online", "opening", "parlour", "puzzle", "prize", "dangerous", "power", "small", "strongman", "trust", "word", "to throw a", "blame", "in"]],
    ["kind of game", ["risk", "clue", "life", "go", "war", "snap", "golf", "hearts", "minesweeper", "portal", "doom", "tetris", "snake", "catan", "codenames", "taboo", "boggle", "set", "mastermind", "twister", "charades", "darts", "pool", "squash", "cricket", "poker", "uno", "monopoly", "operation", "battleship", "tag", "catch", "marbles", "telephone", "werewolf", "hangman", "20 questions", "2048", "sokoban", "bingo", "lotto", "bridge", "senet", "simon says", "countdown"]],
    ["game (hunting)", ["deer", "boar", "pheasant", "grouse", "hare", "rabbit", "partridge", "quail", "woodcock", "snipe", "elk", "moose", "venison", "mallard", "teal", "pigeon", "ptarmigan", "capercaillie", "roe deer", "wild turkey", "wildfowl", "squirrel", "bison", "antelope"]],
    ["game (willing)", ["willing", "eager", "keen", "up for it", "plucky", "daring", "gutsy", "spunky", "intrepid", "ready", "enthusiastic", "prepared", "inclined", "gung-ho", "spirited", "adventurous", "unafraid", "valiant", "courageous", "raring", "dauntless", "audacious"]],
    ["“game” abroad", ["Spiel", "jeu", "juego", "gioco", "spel", "gra", "hra", "jogo", "joc", "igra", "peli", "mäng", "játék", "oyun", "leikur", "spil", "ludus", "gêm", "cluiche", "permainan", "mchezo", "spēle", "žaidimas"]],
    ["game theory", ["zero-sum", "Nash equilibrium", "prisoner's dilemma", "payoff", "minimax", "dominant strategy", "stag hunt", "tit for tat", "Pareto optimum", "mixed strategy", "chicken", "Shapley value", "best response", "subgame", "backward induction", "ultimatum", "public goods", "battle of the sexes", "matching pennies", "tragedy of the commons", "free rider", "coalition", "signaling", "bargaining", "auction"]],
    ["gaming jargon", ["respawn", "noob", "boss", "loot", "quest", "combo", "speedrun", "NPC", "hitpoints", "save point", "grind", "level up", "power-up", "cheat code", "lag", "nerf", "buff", "frag", "camping", "spawn", "loadout", "cooldown", "aggro", "DLC", "easter egg", "side quest", "tank", "healer", "GG", "AFK", "PvP", "sandbox", "open world", "checkpoint", "achievement", "leaderboard", "mod", "patch", "skin", "rage quit"]],
  ],
];
var colors = ["#c49", "#68b", "#4a3"];
var wordOf = {};  // Category name -> title word index
var categoryOf = [];  // Board index -> category name
var titles = document.querySelectorAll("h1 span");

function shuffleArray(array) {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
}

function newBoard() {
  shuffleArray(colors);
  titles.forEach(t => t.style.color = "");
  document.getElementById("done").hidden = true;
  document.getElementById("board").innerHTML = "";
  var wordlist = [];
  words.forEach((cats, i) => {
    var [name, items] = cats[Math.floor(Math.random() * cats.length)];
    items = items.slice();
    shuffleArray(items);
    items.slice(0, 3).forEach(item => wordlist.push([item, name]));
    wordOf[name] = i;
  });
  shuffleArray(wordlist);
  categoryOf = wordlist.map(w => w[1]);
  setUpBoard(wordlist.map(w => w[0]));
}

function check(a, b) {
  return {valid: categoryOf[a.index] == categoryOf[b.index], category: a.cluster.length + b.cluster.length == size && categoryOf[a.index]};
}

function moved() {}

function categoryDone(b, category) {
  var i = wordOf[category];
  titles[i].style.color = b.style.background = colors[i];
  b.style.color = "white";
  if (score == size * size - size) document.getElementById("done").hidden = false;
}

newBoard();
</script>
<?php
}
?>
</body>
</html>
