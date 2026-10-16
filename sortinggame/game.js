// Feature requests:
// 1) Don't penalize already guessed.


// Globals in board.js, state is set by game.php
let trophy;  // The button reopening the end screen, once the game is finished
let highscores;  // Fetched once, when the end screen first opens
let stats;  // Feedback on this player's mistakes, fetched with the highscores



// Functions


function send(data) {
  return fetch('receiver.php', {method: 'POST', body: new URLSearchParams({seed: state.seed, ...data})});
}


function setLayout(l) {
  document.getElementById("board").className = l;
  send({action: 'layout', layout: l});
}


function sendSlots() {
  send({action: 'slots', slots: JSON.stringify([...document.getElementById('board').children].filter(b => b.index >= 0).map(b => b.index))});
}


//Moves the holes left by merged words to the end, where they don't show, so the remaining buttons close up
function reflow() {
  const board = document.getElementById('board');
  if (!board.querySelector('.gone:has(~ :not(.gone))')) return;
  board.querySelectorAll('.gone').forEach(b => board.append(b));
  sendSlots();
}


//Once every word is in a finished category, a trophy button takes the first hole, or goes after the categories, and opens the end screen
function end(show) {
  if (trophy || !buttons.every(b => b.disabled || b.category)) return;
  trophy = document.createElement('button');
  trophy.textContent = '🏆';
  trophy.cluster = ['🏆'];
  trophy.onclick = showEnd;
  const board = document.getElementById('board');
  const gap = board.querySelector('.gone');
  gap ? gap.replaceWith(trophy) : board.append(trophy);
  if (show) showEnd();
}


//The end screen: the ten best players of this seed by combinations minus mistakes, and this player below them if further down
async function showEnd() {
  highscores ??= await (await send({action: 'highscores'})).json();
  stats ??= await (await send({action: 'stats'})).json();
  const esc = s => s.replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');
  const word = i => '“' + esc(state.words[i]) + '”';
  const line = (label, entries, show) => entries.length ? `<li>${label}: ${entries.map(show).join(', ')}</li>` : '';
  const feedback = line('Wrong pairs tried most', stats.pairs, ([p, n]) => p.split(',').map(word).join(' and ') + ` (${n} times)`) +
    line('Most mistakes', stats.worst, ([c, n]) => `${esc(c)} (${n})`) +
    line('Fewest mistakes', stats.best, ([c, n]) => `${esc(c)} (${n})`) +
    line('Words tried most', stats.tried, ([i, n]) => `${word(i)} (${n} times)`);
  const link = location.origin + location.pathname + '?go=game&seed=' + state.seed;
  openPopout('<b>Congratulations!</b><table><tr><th>#</th><th>Name</th><th>Score</th><th>Combinations</th><th>Mistakes</th></tr>' +
    highscores.map(p => `<tr${p.self ? ' class="self"' : ''}><td>${p.rank}</td><td>${esc(p.name || 'Anonymous')}</td><td>${p.score - p.mistakes}</td><td>${p.score}</td><td>${p.mistakes}</td></tr>`).join('') +
    `</table><span>Your final score is ${score - mistakes} (${score} combinations, ${mistakes} mistakes).${state.multiplayer ? '' : ' Share this link'}</span>` +
    (state.multiplayer ? '' : `<span class="codebox"><input class="code" value="${link}" readonly><button type="button" class="copy" title="Copy link" onclick="event.stopPropagation(); this.previousElementSibling.select(); navigator.clipboard.writeText(this.previousElementSibling.value);">⧉</button></span>` +
      '<span>to invite others to compete against this score.</span>') +
    '<button form="again">Start another game with the same settings</button>' + (feedback && `<ul>${feedback}</ul>`));
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


//Each cluster starts at a word without a previous one and continues with the word that has it as previous; in a chain, links holds each word's link category from its previous one
function applyBoard(board, done, links) {
  const next = [];
  board.forEach((p, i) => { if (p >= 0) next[p] = i; });
  buttons.forEach((b, i) => {
    if (board[i] >= 0) return hole(b);
    b.cluster = [state.words[i]];
    b.links = [];
    for (let j = next[i]; j !== undefined; j = next[j]) {
      b.cluster.push(state.words[j]);
      if (chain) b.links.push(links[j]);
    }
    showCluster(b);
    if (done[i]) finishCategory(b, done[i]);
  });
  if (selected.disabled || selected.category) deselect();
}


//Multiplayer: fetches the board and the other players every second, until the game is finished
var version = -1;
async function poll() {
  const r = await fetch('receiver.php?' + new URLSearchParams({seed: state.seed, version: version}));
  if (r.status == 200) {
    const s = await r.json();
    version = s.version;
    applyBoard(s.board, s.done, s.links);
    moved();
    document.getElementById('players').textContent = s.players.map(p => (p.display_name || 'Anonymous') + ' ' + p.score + '/' + p.mistakes).join(', ');
    if (s.finished) return;
  }
  setTimeout(poll, 1000);
}


//Called by the board after every click
function moved() {
  document.getElementById("score").textContent = score;
  document.getElementById("mistakes").textContent = mistakes;
  if (document.getElementById('reflow').checked) reflow();
  end(true);
}


//Called by the board when a category is complete
function categoryDone() {}


//These automatically run when the page loads: the board is built from the words, the saved state is applied to it
chain = state.mode == 'chain';
setUpBoard(state.words);
score = state.score;
mistakes = state.mistakes;
const buttons = [...document.getElementById('board').children];
applyBoard(state.board, state.done, state.links);
if (state.slots) document.getElementById('board').replaceChildren(...state.slots.map(i => buttons[i]));
end(false);
if (state.multiplayer) poll();
