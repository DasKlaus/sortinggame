<html><!-- Originally created in 2025 by Thomas Colthurst --><!-- Original at https://thomaswc.com/2025.html --><!-- Adapted in 2026 by Victoria Roberts:--><!-- Added new data - each set with 20+ items for greater replayability --><!-- Added buttons to generate new random puzzles at several sizes (and reset the board) --><!-- Added a checkbox that allows the user to hide the new puzzle buttons during gameplay, to reduce the chance of accidentally clicking on them mid-game --><!-- Added a visible, textual attribution/link crediting Thomas Colthurst --><!-- Changed the checkCategories function to ensure it validates all data, not just the data for a particular puzzle --><!-- Fixed the issue with Unicode rendering by adding charset=utf-8 to the <header> --><!-- Hid the Deselect button, since I broke it somehow --><!-- Released under a Creative Commons Share Alike with Attribution License --><!-- See https://creativecommons.org/licenses/by-sa/4.0/ --><head>
        <title>The Sorting Game</title>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

        <!-- Favicon -->
        <link rel="apple-touch-icon-precomposed" sizes="57x57" href="https://victoria.danclan.com/favicon/apple-touch-icon-57x57.png">
        <link rel="apple-touch-icon-precomposed" sizes="114x114" href="https://victoria.danclan.com/favicon/apple-touch-icon-114x114.png">
        <link rel="apple-touch-icon-precomposed" sizes="72x72" href="https://victoria.danclan.com/favicon/apple-touch-icon-72x72.png">
        <link rel="apple-touch-icon-precomposed" sizes="144x144" href="https://victoria.danclan.com/favicon/apple-touch-icon-144x144.png">
        <link rel="apple-touch-icon-precomposed" sizes="60x60" href="https://victoria.danclan.com/favicon/apple-touch-icon-60x60.png">
        <link rel="apple-touch-icon-precomposed" sizes="120x120" href="https://victoria.danclan.com/favicon/apple-touch-icon-120x120.png">
        <link rel="apple-touch-icon-precomposed" sizes="76x76" href="https://victoria.danclan.com/favicon/apple-touch-icon-76x76.png">
        <link rel="apple-touch-icon-precomposed" sizes="152x152" href="https://victoria.danclan.com/favicon/apple-touch-icon-152x152.png">
        <link rel="icon" type="image/png" href="https://victoria.danclan.com/favicon/favicon-196x196.png" sizes="196x196">
        <link rel="icon" type="image/png" href="https://victoria.danclan.com/favicon/favicon-96x96.png" sizes="96x96">
        <link rel="icon" type="image/png" href="https://victoria.danclan.com/favicon/favicon-32x32.png" sizes="32x32">
        <link rel="icon" type="image/png" href="https://victoria.danclan.com/favicon/favicon-16x16.png" sizes="16x16">
        <link rel="icon" type="image/png" href="https://victoria.danclan.com/favicon/favicon-128.png" sizes="128x128">

    <link rel="stylesheet" href="style.css"></head>
    

    <body id="body">
        
        <div class="floating">
           Combine items two at a time into categories. Score:&nbsp;<span id="score">0</span> Mistakes:&nbsp;<span id="mistakes">0</span> 
            <button type="button" id="deselect" disabled="disabled" onclick="deselect()">Deselect</button>Adapted from <a href="https://thomaswc.com/2025.html">Thomas Colthurst’s original</a>. <a href="index.php">Site notice</a>
            <br>
            <div id="buttons">
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(10)">Generate 10x10 puzzle</button>
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(15)">Generate 15x15 puzzle</button>
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(20)">Generate 20x20 puzzle</button>
                <select id="lang" onchange="location.search = '?go=game&lang=' + this.value"><option value="en">en</option><option value="de">de</option></select>&nbsp;
            </div>
            <select id="layout" onchange="setLayout(this.value)"><option value="grid">Grid</option><option value="wrap">Wrap</option><option value="tight">Tight</option></select>
            <button type="button" onclick="reflow()">Reflow</button>
            <button type="button" onclick="order()">Order</button>
            <input type="checkbox" id="hide_buttons_checkbox" onclick="hideButtonsCheckbox()"><label for="hide_buttons_checkbox">Hide buttons during gameplay</label>
        </div>

        <div id="board"></div>




    
    
    <script>
// Categories: en.js or de.js from sorting_js.py, chosen by ?lang=en or ?lang=de
var lang = new URLSearchParams(location.search).get("lang") || "en";
document.getElementById("lang").value = lang;
document.write(`<script src="${lang}.js"><\/script>`);
    </script>
    <script src="game.js"></script>


</body></html>