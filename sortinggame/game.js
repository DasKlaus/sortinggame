// Feature requests:
// 1) Don't penalize already guessed.


// Globals in board.js, state is set by game.php



// Functions


function send(data) {
  return fetch('receiver.php', {method: 'POST', body: new URLSearchParams({seed: state.seed, ...data})});
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


//Called by the board to merge b's cluster into a's, the server checks it.
//The next poll redraws the whole board, also over a poll answer that crossed this merge and has already shown it.
async function check(a, b) {
  const answer = await (await send({action: 'merge', a: a.index, b: b.index})).json();
  version = -1;
  return answer;
}


//Each cluster starts at a word without a previous one and continues with the word that has it as previous
function applyBoard(board, done) {
  const next = [];
  board.forEach((p, i) => { if (p >= 0) next[p] = i; });
  buttons.forEach((b, i) => {
    if (board[i] >= 0) return hole(b);
    b.cluster = [state.words[i]];
    for (let j = next[i]; j !== undefined; j = next[j]) b.cluster.push(state.words[j]);
    showCluster(b);
    if (done[i]) finishCategory(b, done[i]);
  });
  if (selected.disabled) deselect();
}


//Multiplayer: fetches the board and the other players every second, until the game is finished
var version = -1;
async function poll() {
  const r = await fetch('receiver.php?' + new URLSearchParams({seed: state.seed, version: version}));
  if (r.status == 200) {
    const s = await r.json();
    version = s.version;
    applyBoard(s.board, s.done);
    document.getElementById('players').textContent = s.players.map(p => (p.display_name || 'Anonymous') + ' ' + p.score + '/' + p.mistakes).join(', ');
    if (s.finished) return;
  }
  setTimeout(poll, 1000);
}


//Called by the board after every click
function moved() {
  document.getElementById("score").textContent = score;
  document.getElementById("mistakes").textContent = mistakes;
}


//Called by the board when a category is complete
function categoryDone() {}


//These automatically run when the page loads: the board is built from the words, the saved state is applied to it
setUpBoard(state.words);
score = state.score;
mistakes = state.mistakes;
const buttons = [...document.getElementById('board').children];
applyBoard(state.board, state.done);
if (state.slots) document.getElementById('board').replaceChildren(...state.slots.map(i => buttons[i]));
if (state.multiplayer) poll();
