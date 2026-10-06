// Feature requests:
// 1) Don't penalize already guessed.


//Handles checkbox behavior
function hideButtonsCheckbox() {
    var decider = document.getElementById('hide_buttons_checkbox');
    var buttonsDiv = document.getElementById('buttons');
    var boardDiv = document.getElementById('board');
    if((decider.checked) && (boardDiv.childNodes.length === 0)) {
        buttonsDiv.style.display = 'inline-block';
    } else if((decider.checked) && (boardDiv.childNodes.length > 0)) {
        buttonsDiv.style.display = 'none';
    } else if((!decider.checked) && (boardDiv.childNodes.length === 0)) {
        buttonsDiv.style.display = 'inline-block';
    } else if((!decider.checked) && (boardDiv.childNodes.length > 0))  {
        buttonsDiv.style.display = 'inline-block';
    }
}

        
// Globals (more in board.js)
// var wordlist = [];  // Actually, a list of (word, category) pairs




//Chosen categories, to be populated at random
var cats = {};
var wordlist = [];
    




// Functions


//Ensures DOM is empty for generating a new puzzle, and resets score/mistake counter
function resetBoard(){
    const myNode = document.getElementById("board");
    myNode.innerHTML = '';
    const myScoreNode = document.getElementById("score");
    const myMistakeNode = document.getElementById("mistakes");
    myScoreNode.innerHTML = '0';
    myMistakeNode.innerHTML = '0';
    mistakes = 0;
}


//Randomizing the arrays
function randomlyPickCategories(numofcats){

    //Generates an array of sequential numbers the length of the allcatdata array [0,1,2,3,...]
    let numarray = Array.from({length: allcatdata.length}, (e, i)=> i)
    
    //Fisher–Yates Shuffle
    //Randomizes numarray
    function shuffle(array) {
        var i = array.length,
            j = 0,
            temp;
        while (i--) {
            j = Math.floor(rand() * (i+1));
            temp = array[i];
            array[i] = array[j];
            array[j] = temp;
        }
        return array;
    }
    var ranNums = shuffle(numarray);

    //Items of category a that are in none of the other picked categories
    let sets = allcatdata.map(c => new Set(c[1]));
    function exclusive(a, picks) {
        return allcatdata[a][1].filter(w => !picks.some(b => b != a && sets[b].has(w)));
    }

    //Adds categories as long as every picked category keeps enough exclusive items,
    //so that no word on the board fits two categories
    let chosen = [];
    for (const c of ranNums) {
        if (chosen.length == numofcats) break;
        let trial = chosen.concat([c]);
        if (trial.every(a => exclusive(a, trial).length >= numofcats)) chosen = trial;
    }

    cats = {};
    for (const c of chosen) {
        cats[allcatdata[c][0]] = shuffle(exclusive(c, chosen)).slice(0, numofcats);
    }
}


function setLayout(l) {
  document.getElementById("board").className = l;
  document.getElementById("layout").value = l;
  localStorage.setItem('layout', l);
}


//Removes the holes left by merged words, so the remaining buttons close up
function reflow() {
  document.querySelectorAll('.gone').forEach(b => b.remove());
  saveState();
}


//Moves the biggest clusters to the front (finished categories first) and holes to the end; sort is stable, so the rest keep their order
function order() {
  const b = document.getElementById('board');
  const size = x => x.classList.contains('gone') ? 0 : x.cluster.length;
  [...b.children].sort((x, y) => size(y) - size(x)).forEach(x => b.appendChild(x));
  saveState();
}


function getRandomHexColor() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16);
}



function checkCategories(numofcats, wordlist) {
  // Check that each category has the specific number of entries.
  for (const [key, value] of Object.entries(cats)) {
    if (value.length < numofcats) {
      alert(`Entry for ${key} has length ${value.length}`);
    }
    for (let i = 0; i < numofcats; i++) {
      wordlist.push([value[i], key]);
    }
  }
  putWordsInBoard(wordlist)
}



//Called by the board after every click
function moved() {
  document.getElementById("deselect").disabled = selected == 0;
  document.getElementById("score").textContent = score;
  document.getElementById("mistakes").textContent = mistakes;
  saveState();
}


//Called by the board when a category is complete
function categoryDone() {
  //Show buttons once all are complete (in case checkbox was checked, redundant otherwise)
  if (score == size * size - size) {
    document.getElementById('buttons').style.display = 'inline-block';
  }
}



//Saves to local storage so the game isn't lost if the page reloads (common on mobile)
function saveState() {
    localStorage.clear();

    localStorage.setItem('layout', document.getElementById('layout').value);
    localStorage.setItem('seed', seed);
    localStorage.setItem('score', score + '');
    localStorage.setItem('mistakes', mistakes + '');
    localStorage.setItem('numofcats', size + '');

    if (document.getElementById('hide_buttons_checkbox').checked) {
        localStorage.setItem('checkbox', "True" + '');
        console.log("checked")
    }
    else {
        localStorage.setItem('checkbox', "False" + '');
        console.log("unchecked")
    }
    
    var buttons = document.getElementById('board').children;
    for (let i = 0; i < buttons.length; i++) {
        let button = buttons[i];
        localStorage.setItem(`${i}_category`, button.category);
        localStorage.setItem(`${i}_cluster`, JSON.stringify(button.cluster));
        localStorage.setItem(`${i}_innerHTML`, button.innerHTML);
        localStorage.setItem(`${i}_title`, button.title);
    }
}



//retrieves any locally saved data
function loadState(numofcats) {
    let check = localStorage.getItem('checkbox');
    if (check == "True") {
        document.getElementById("hide_buttons_checkbox").checked = true;    
    }
    hideButtonsCheckbox()

    s = localStorage.getItem('score');
    if (s == null) {
        //clearing everything out if this is the first time the page has been loaded
        const myNode = document.getElementById("board");
        myNode.innerHTML = '';
        return;
    }
    score = Number(s);
    document.getElementById("score").textContent = score;
    
    s = localStorage.getItem('mistakes');
    if (s == null) {
        return;
    }
    mistakes = Number(s);
    document.getElementById("mistakes").textContent = mistakes;
    
    var buttons = document.getElementById("board").children;
    for (let i = 0; i < buttons.length; i++) {
        var button = buttons[i];
        button.category = localStorage.getItem(`${i}_category`);

        button.cluster = JSON.parse(localStorage.getItem(`${i}_cluster`));
        if (Array.isArray(button.cluster) && button.cluster.length == numofcats) {
            finishCategory(button);
        }
        button.innerHTML = localStorage.getItem(`${i}_innerHTML`);
        button.title = localStorage.getItem(`${i}_title`);
        if (button.innerHTML == '') {
            button.classList.add('gone');
        }
    }
}
            



//"Generate puzzle" buttons: a new seed of language, size and a random part, e.g. en15-k3x9q
function generatepuzzle(size){
        location.search = '?go=game&seed=' + document.getElementById('lang').value + size + '-' + Math.random().toString(36).slice(2, 7);
}


//Mulberry32, seeded by a hash of the seed string
function seededRandom(str) {
  let a = 0;
  for (const c of str) a = Math.imul(a ^ c.charCodeAt(0), 2654435761);
  return () => {
    a = a + 0x6D2B79F5 | 0;
    let t = Math.imul(a ^ a >>> 15, 1 | a);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  };
}


//Builds the board for a seed
function playSeed(){
        const size = Number(seed.match(/^[a-z]+(\d+)-/)[1]);
        rand = seededRandom(seed);
        localStorage.clear();
        localStorage.setItem('layout', document.getElementById('layout').value);
        resetBoard();
        setUpBoard(size);
        randomlyPickCategories(size);
        checkCategories(size, []);
        hideButtonsCheckbox(); //if box is checked, hide buttons now
}


//These automatically run when the page loads: a new seed in the URL builds its board, otherwise any saved local data is loaded.
var urlSeed = new URLSearchParams(location.search).get('seed');
var seed = urlSeed || localStorage.getItem('seed');
setLayout(localStorage.getItem('layout') || 'grid');
if (urlSeed && urlSeed != localStorage.getItem('seed')) {
    playSeed();
} else {
    setUpBoard(Number(localStorage.getItem('numofcats')));
    loadState(Number(localStorage.getItem('numofcats')));
}
