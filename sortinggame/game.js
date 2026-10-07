// Feature requests:
// 1) Don't penalize already guessed.


// Globals in board.js, state is set by game.php



// Functions


//Picks numofcats categories at random and returns numofcats of their items each, as (word, category) pairs
function randomlyPickCategories(numofcats){

    //Generates an array of sequential numbers the length of the allcatdata array [0,1,2,3,...], randomized
    let ranNums = Array.from({length: allcatdata.length}, (e, i)=> i);
    shuffleArray(ranNums);

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

    let wordlist = [];
    for (const c of chosen) {
        let items = exclusive(c, chosen);
        shuffleArray(items);
        items.slice(0, numofcats).forEach(w => wordlist.push([w, allcatdata[c][0][0]]));
    }
    return wordlist;
}


function send(data) {
  fetch('receiver.php', {method: 'POST', body: new URLSearchParams({seed: state.seed, ...data})});
}


function setLayout(l) {
  document.getElementById("board").className = l;
  send({action: 'layout', layout: l});
}


function sendSlots() {
  send({action: 'slots', slots: JSON.stringify([...document.getElementById('board').children].map(b => b.index))});
}


//Removes the holes left by merged words, so the remaining buttons close up
function reflow() {
  document.querySelectorAll('.gone').forEach(b => b.remove());
  sendSlots();
}


//Moves the biggest clusters to the front (finished categories first) and holes to the end; sort is stable, so the rest keep their order
function order() {
  const b = document.getElementById('board');
  const size = x => x.classList.contains('gone') ? 0 : x.cluster.length;
  [...b.children].sort((x, y) => size(y) - size(x)).forEach(x => b.appendChild(x));
  sendSlots();
}


//Called by the board after every click
function moved(change) {
  document.getElementById("score").textContent = score;
  document.getElementById("mistakes").textContent = mistakes;
  if (change) send(change);
}


//Called by the board when a category is complete
function categoryDone() {}


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


//These automatically run when the page loads: the seed builds the board, the saved state is applied to it
rand = seededRandom(state.seed);
setUpBoard(randomlyPickCategories(state.size));
score = state.score;
mistakes = state.mistakes;

//Each cluster starts at a word without a previous one and continues with the word that has it as previous
const buttons = [...document.getElementById('board').children];
const next = [];
state.board.forEach((p, i) => { if (p >= 0) next[p] = i; });
buttons.forEach((b, i) => {
  if (state.board[i] >= 0) return hole(b);
  for (let j = next[i]; j !== undefined; j = next[j]) b.cluster.push(buttons[j].cluster[0]);
  showCluster(b);
  if (b.cluster.length == size) finishCategory(b);
});
if (state.slots) document.getElementById('board').replaceChildren(...state.slots.map(i => buttons[i]));
