// Feature requests:
// 1) Don't penalize already guessed.


// Globals in board.js, state is set by game.php
let trophy;  // The button reopening the end screen, once the game is finished
let highscores;  // Fetched once, when the end screen first opens
let stats;  // Feedback on this player's mistakes, fetched with the highscores
let achievements = [];  // Achievements this player earned with the merge that finished the game, until they are on the board
let curation;  // Suggestions from this player's mistakes and their saved answers, fetched when the curation form first opens



// Functions


const esc = s => s.replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');


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


//A trophy (or other symbol) button takes the first hole, or goes after the categories
function award(onclick, symbol = '🏆') {
  const b = document.createElement('button');
  b.textContent = symbol;
  b.cluster = [symbol];
  b.onclick = onclick;
  const board = document.getElementById('board');
  const gap = board.querySelector('.gone');
  gap ? gap.replaceWith(b) : board.append(b);
  return b;
}


//Once every word is in a finished category, a trophy opens the end screen, and in a sorting game 🛠️ the curation form
function end(show) {
  if (trophy || !buttons.every(b => b.disabled || b.category)) return;
  trophy = award(showEnd);
  if (!chain) award(showCuration, '🛠️');
  if (show) showEnd();
}


//The curation form of a finished sorting game: suggestions from this player's mistakes, and every category with its words for anything else.
//Each answer is saved for review as soon as it's given, under the key [kind, item, category, other] of the feedback table; tapping a selected answer takes it back.
//A membership's answer is the state it should have: normal, obscure or none. The "also belongs to" chips cycle through normal, obscure and no answer.
async function showCuration() {
  if (!curation) {
    curation = await (await send({action: 'curation'})).json();
    curation.saved = Object.fromEntries(curation.saved.map(([kind, item, category, other, value]) => [JSON.stringify([kind, item, category, other]), value]));
  }
  const cats = buttons.filter(b => b.category);
  const of = Object.fromEntries(cats.flatMap(b => b.cluster.map(w => [w, b.category])));
  const tag = c => `<span class="tag" style="background: ${stringToLightColor(c)}">${esc(c)}</span>`;
  const key = (...k) => esc(JSON.stringify(k));
  const apart = (c, d) => key('apart', '', ...[c, d].sort());
  const options = (k, list) => '<span class="choices">' + list.map(([v, label]) => `<button type="button" data-key="${k}" data-value="${v}">${label}</button>`).join('') + '</span>';
  const member = (w, c, labels) => options(key('membership', w, c, ''), [['normal', labels[0]], ['obscure', 'Obscure'], ['none', labels[1]]]);
  const card = (head, info, choices) => `<div class="card">${head}<small class="info">${info}</small>${choices}</div>`;
  const column = (title, info, cards) => cards.length ? `<div class="column"><b>${title}</b><small>${info}</small>${cards.join('')}` +
    (cards.length > 3 ? `<button type="button" class="more" onclick="this.parentNode.classList.add('open'); this.remove()">${cards.length - 3} more</button>` : '') + '</div>' : '';
  const columns = column('Should this have counted?', "Words you tried with a category's cluster. Larger clusters and repeated tries rank higher.",
      curation.near.map(([w, c, n]) => card(`<strong>${esc(w)}</strong> with ${tag(c)} · ${n}×`, esc(cats.find(b => b.category == c).cluster.join('; ')), member(w, c, ['Counts', 'Fair mistake'])))) +
    column('Mixed-up categories', 'Pairs with the most mistakes between their clusters.',
      curation.mixups.map(([c, d, n]) => card(`${tag(c)} and ${tag(d)}`, n == 1 ? '1 mistake' : `${n} mistakes`, options(apart(c, d), [['1', 'Keep apart'], ['0', 'Fine together']])))) +
    column('Found only after mistakes', 'Words that joined their category after failed tries, at any point in the game.',
      curation.late.map(([w, n, tried]) => card(`<strong>${esc(w)}</strong> in ${tag(of[w])}`, `${n} failed ${n == 1 ? 'try' : 'tries'}: ` + tried.map(([c, k]) => `${tag(c)} ${k}×`).join(', '), member(w, of[w], ['Fine', 'Wrong']))));
  const editor = w => `<div class="card"><small>Spelling</small><input data-key="${key('spelling', w, of[w], '')}" value="${esc(curation.saved[JSON.stringify(['spelling', w, of[w], ''])] ?? w)}">` +
    `<small>In ${tag(of[w])}</small>${member(w, of[w], ['Fine', 'Wrong'])}<small>Also belongs to (tap once: counts, twice: obscure)</small>` +
    cats.filter(b => b.category != of[w]).map(b => `<button type="button" class="chip" data-key="${key('membership', w, b.category, '')}" data-cycle>${tag(b.category)}</button>`).join('') + '</div>';
  const categories = cats.map(b => `<details><summary>${tag(b.category)}</summary>` + b.cluster.map(w => `<button type="button" class="chip" data-word="${esc(w)}">${esc(w)}</button>`).join('') +
    '<div class="editor"></div><small>Keep apart from</small>' + cats.filter(o => o != b).map(o => `<button type="button" class="chip" data-key="${apart(b.category, o.category)}" data-value="1">${tag(o.category)}</button>`).join('') + '</details>').join('');
  openPopout(`<div class="curation"><button type="button" class="close" onclick="popout.hidden = true">✕</button><b>Improve the categories</b>` +
    `<small>${columns ? 'Built from your mistakes. ' : ''}Answers are saved for review; nothing on the board changes. <i>Obscure</i>: correct, but that category never uses it, so the two never share a board.</small>` +
    `<div class="columns">${columns}</div><details${columns ? '' : ' open'}><summary>Anything else</summary><small>For what your mistakes didn't catch. Tap a word to fix its spelling or memberships.</small>` +
    `<div class="columns"><div>${categories}</div><div><small>Free-form feedback</small><textarea rows="4" data-key="${key('text', '', '', '')}">${esc(curation.saved[JSON.stringify(['text', '', '', ''])] ?? '')}</textarea></div></div></details></div>`);
  const form = popout.querySelector('.curation');
  const mark = () => form.querySelectorAll('button[data-key]').forEach(b => {
    const v = curation.saved[b.dataset.key];
    b.classList.toggle('on', 'cycle' in b.dataset ? v == 'normal' || v == 'obscure' : v == b.dataset.value);
    b.classList.toggle('obscure', 'cycle' in b.dataset && v == 'obscure');
  });
  const save = (k, value) => {
    if (value) curation.saved[k] = value;
    else delete curation.saved[k];
    const [kind, item, category, other] = JSON.parse(k);
    send({action: 'feedback', kind, item, category, other, value});
    mark();
  };
  mark();
  form.onclick = e => {
    e.stopPropagation();
    const b = e.target.closest('button');
    if (b?.dataset.word) {
      b.parentNode.querySelector('.editor').innerHTML = editor(b.dataset.word);
      return mark();
    }
    if (!b?.dataset.key) return;
    const v = curation.saved[b.dataset.key];
    save(b.dataset.key, 'cycle' in b.dataset ? {normal: 'obscure', obscure: ''}[v] ?? 'normal' : v == b.dataset.value ? '' : b.dataset.value);
  };
  //Spelling and free text: a spelling back to the word itself, or an emptied text, takes the answer back
  form.onchange = e => {
    const v = e.target.value.trim();
    save(e.target.dataset.key, v == JSON.parse(e.target.dataset.key)[1] ? '' : v);
  };
}


//The end screen: the ten best players of this seed by combinations minus mistakes, and this player below them if further down
async function showEnd() {
  highscores ??= await (await send({action: 'highscores'})).json();
  stats ??= await (await send({action: 'stats'})).json();
  const word = i => '“' + esc(state.words[i]) + '”';
  const line = (label, entries, show) => entries.length ? `<li>${label}: ${entries.map(show).join(', ')}</li>` : '';
  const feedback = line('Wrong pairs tried most', stats.pairs, ([p, n]) => p.split(',').map(word).join(' and ') + ` (${n} times)`) +
    line('Most mistakes', stats.worst, ([c, n]) => `${esc(c)} (${n})`) +
    line('Fewest mistakes', stats.best, ([c, n]) => `${esc(c)} (${n})`) +
    line('Words tried most', stats.tried, ([i, n]) => `${word(i)} (${n} times)`);
  const link = location.origin + location.pathname + '?go=game&seed=' + state.seed;
  // A daily shares plain text instead: the address carrying no result, a mark per attempt on weekdays, per mistake on weekends, and the streak
  const d = state.daily, n = state.words.length;
  const share = d && `${location.origin + location.pathname}?go=daily&lang=${d.language} #${d.number} ${d.language.toUpperCase()}\n⛓️ ${n} items · ` +
    (n > 9 ? '💥'.repeat(mistakes) || '✨' : stats.log.map(s => s ? '🔗' : '💥').join('')) + (stats.streak >= 2 ? `\n🔥 ${stats.streak} days in a row` : '');
  openPopout('<b>Congratulations!</b><table><tr><th>#</th><th>Name</th><th>Score</th><th>Combinations</th><th>Mistakes</th></tr>' +
    highscores.map(p => `<tr${p.self ? ' class="self"' : ''}><td>${p.rank}</td><td>${esc(p.name || 'Anonymous')}</td><td>${p.score - p.mistakes}</td><td>${p.score}</td><td>${p.mistakes}</td></tr>`).join('') +
    `</table><span>Your final score is ${score - mistakes} (${score} combinations, ${mistakes} mistakes).${state.multiplayer || d ? '' : ' Share this link'}</span>` +
    (d ? `<span class="codebox"><span class="share">${esc(share)}</span><button type="button" class="copy" title="Copy" onclick="event.stopPropagation(); getSelection().selectAllChildren(this.previousElementSibling); navigator.clipboard.writeText(this.previousElementSibling.textContent);">⧉</button></span>` :
      state.multiplayer ? '' : `<span class="codebox"><input class="code" value="${link}" readonly><button type="button" class="copy" title="Copy link" onclick="event.stopPropagation(); this.previousElementSibling.select(); navigator.clipboard.writeText(this.previousElementSibling.value);">⧉</button></span>` +
      '<span>to invite others to compete against this score.</span>') +
    (d ? '' : '<button form="again">Start another game with the same settings</button>') + (feedback && `<ul>${feedback}</ul>`));
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
  if (answer.achievements) achievements = answer.achievements;
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
  //After the end screen's trophy, each new achievement gets one, which marks it seen when opened
  achievements.splice(0).forEach(a => award(() => {
    openPopout(`<b>Congratulations!</b>You've earned a new achievement!<br>${a.description}`);
    send({do: 'confirmachievements', [`seen[${a.badge}]`]: a.level});
  }));
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
