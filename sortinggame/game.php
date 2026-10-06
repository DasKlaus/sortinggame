       
        <div class="floating">
            Combine items two at a time into categories. Score:&nbsp;<span id="score">0</span> Mistakes:&nbsp;<span id="mistakes">0</span>
            <br>
            <div id="buttons">
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(10)">Generate 10x10 puzzle</button>
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(15)">Generate 15x15 puzzle</button>
                <button type="button" id="generatebutton" enabled="" onclick="generatepuzzle(20)">Generate 20x20 puzzle</button>
                <select id="lang"><option value="en">en</option><option value="de">de</option></select>&nbsp;
            </div>
            <select id="layout" onchange="setLayout(this.value)"><option value="grid">Grid</option><option value="wrap">Wrap</option><option value="tight">Tight</option></select>
            <button type="button" onclick="reflow()">Reflow</button>
            <button type="button" onclick="order()">Order</button>
        </div>

        <div id="board"></div>

    <script>
// Categories: en.js or de.js from sorting_js.py, chosen by the seed's language, e.g. ?seed=de15-k3x9q
var lang = (new URLSearchParams(location.search).get("seed") || "en").slice(0, 2);
document.getElementById("lang").value = lang;
document.write(`<script src="${lang}.js"><\/script>`);
    </script>
    <script src="board.js"></script>
    <script src="game.js"></script>