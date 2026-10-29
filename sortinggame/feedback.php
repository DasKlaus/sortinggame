<?php
// The feedback view of a finished sorting game: suggestions from this player's mistakes, and every category with its words for anything else.
// The log is replayed to know the clusters at each attempt, with every player's merges but only this player's mistakes.
// near: a word on its own tried with a cluster of two or more, towards that cluster's category; mixups: every mistake, towards the pair of categories;
// late: a word's tries while on its own, by the other side's category
$seed = $_GET['seed'];
$user = $_SESSION['user_id'];
$game = $mysql->execute_query("select items, multiplayer from game where seed = ? and (multiplayer or created_by = ?) and mode = 'sorting' and finished_at is not null", [$seed, $user])->fetch_assoc();
if (!$game)
{
	echo '<p>Feedback opens once you have finished this sorting game.</p>';
	return;
}
$items = json_decode($game['items']);
$cluster = range(0, count($items) - 1);
$members = array_map(fn($i) => [$i], $cluster);
$near = $mixups = $late = $categories = [];
foreach ($items as [$w, $c]) $categories[$c][] = $w;
ksort($categories, SORT_STRING);
foreach ($mysql->execute_query("select user_id, a, b, success from attempt where seed = ? and (? or user_id = ?) order by id", [$seed, $game['multiplayer'], $user]) as $r)
{
	[$a, $b] = [(int)$r['a'], (int)$r['b']];
	[$ha, $hb] = [$cluster[$a], $cluster[$b]];
	if ($r['success'])
	{
		foreach ($members[$hb] as $m) $cluster[$m] = $ha;
		$members[$ha] = [...$members[$ha], ...$members[$hb]];
		unset($members[$hb]);
	}
	elseif ($r['user_id'] == $user)
	{
		$pair = [$items[$a][1], $items[$b][1]];
		sort($pair, SORT_STRING);
		$p = json_encode($pair, JSON_UNESCAPED_UNICODE);
		$mixups[$p] = ($mixups[$p] ?? 0) + 1;
		foreach ([[$ha, $hb], [$hb, $ha]] as [$x, $h]) if (count($members[$x]) == 1)
		{
			[$w, $c] = [$items[$x][0], $items[$h][1]];
			$late[$w][$c] = ($late[$w][$c] ?? 0) + 1;
			if (count($members[$h]) > 1) $near["$w\n$c"] = [$w, $c, ($near["$w\n$c"][2] ?? 0) + 1, max($near["$w\n$c"][3] ?? 0, count($members[$h]))];
		}
	}
}
// Most tries first; near misses with bigger clusters first among equals
$near = array_values($near);
usort($near, fn($x, $y) => [$y[2], $y[3]] <=> [$x[2], $x[3]]);
arsort($mixups);
uasort($late, fn($x, $y) => array_sum($y) <=> array_sum($x));
foreach ($late as &$tried) arsort($tried);
unset($tried);
$data = [
	'seed' => $seed,
	'near' => array_map(fn($n) => array_slice($n, 0, 3), $near),
	'mixups' => array_map(fn($p, $n) => [...json_decode($p), $n], array_keys($mixups), $mixups),
	'late' => array_map(fn($w, $tried) => [(string)$w, array_sum($tried), array_map(null, array_keys($tried), $tried)], array_keys($late), $late),
	'categories' => array_map(fn($c, $words) => [(string)$c, $words], array_keys($categories), $categories),
	'saved' => $mysql->execute_query("select kind, item, category, other, value from feedback where seed = ? and user_id = ?", [$seed, $user])->fetch_all()];
?>
<div id="feedback">
<h2>Improve the categories</h2>
<small>Answers are saved for review; nothing on the board changes. <i>Obscure</i>: correct, but that category never uses it, so the two never share a board.</small>
<div id="columns" class="columns"></div>
<details<?php echo $mixups ? '' : ' open' ?>><summary>Anything else</summary>
<small>For what your mistakes didn't catch. Tap a word to fix its spelling or memberships.</small>
<div class="columns"><div id="categories"></div><div><small>Free-form feedback</small><textarea rows="4" data-key='["text","","",""]'></textarea></div></div>
</details>
</div>
<script>var data = <?php echo json_encode($data) ?>;</script>
<script src="board.js"></script>
<script>
// Each answer is saved for review as soon as it's given, under the key [kind, item, category, other] of the feedback table; tapping a selected answer takes it back.
// A membership's answer is the state it should have: normal, obscure or none; the "also belongs to" chips cycle through normal, obscure and no answer.
// A column shows its unanswered suggestions with two or more mistakes, up to ten, the rest behind "more"; answered ones go under a collapsed header at its top.
const page = document.getElementById('feedback');
const saved = Object.fromEntries(data.saved.map(([kind, item, category, other, value]) => [JSON.stringify([kind, item, category, other]), value]));
const words = Object.fromEntries(data.categories);
const of = Object.fromEntries(data.categories.flatMap(([c, ws]) => ws.map(w => [w, c])));
const key = (...k) => JSON.stringify(k);
const apart = (c, d) => key('apart', '', ...[c, d].sort());
const tag = c => `<span class="tag" style="background: ${stringToLightColor(c)}">${esc(c)}</span>`;
const choices = (k, list) => '<span class="choices">' + list.map(([v, label]) => `<button type="button" data-key="${esc(k)}" data-value="${v}">${label}</button>`).join('') + '</span>';
const membership = (yes, no) => [['normal', yes], ['obscure', 'Obscure'], ['none', no]];
const card = (k, n, head, info, list) => ({key: k, n, html: `<div class="card">${head}<small class="info">${info}</small>${choices(k, list)}</div>`});
const columns = [
  {title: 'Should this have counted?', info: "Words you tried with a category's cluster. Larger clusters and repeated tries rank higher.",
    cards: data.near.map(([w, c, n]) => card(key('membership', w, c, ''), n, `<strong>${esc(w)}</strong> with ${tag(c)} · ${n}×`, esc(words[c].join('; ')), membership('Counts', 'Fair mistake')))},
  {title: 'Mixed-up categories', info: 'Pairs with the most mistakes between their clusters.',
    cards: data.mixups.map(([c, d, n]) => card(apart(c, d), n, `${tag(c)} and ${tag(d)}`, n == 1 ? '1 mistake' : `${n} mistakes`, [['1', 'Keep apart'], ['0', 'Fine together']]))},
  {title: 'Found only after mistakes', info: 'Words that joined their category after failed tries, at any point in the game.',
    cards: data.late.map(([w, n, tried]) => card(key('membership', w, of[w], ''), n, `<strong>${esc(w)}</strong> in ${tag(of[w])}`,
      `${n} failed ${n == 1 ? 'try' : 'tries'}: ` + tried.map(([c, k]) => `${tag(c)} ${k}×`).join(', '), membership('Fine', 'Wrong')))},
].filter(c => c.cards.length);

function showColumns() {
  for (const d of document.querySelectorAll('#columns details')) columns[d.parentNode.dataset.column].answeredOpen = d.open;
  document.getElementById('columns').innerHTML = columns.map((c, i) => {
    const answered = c.cards.filter(x => saved[x.key]), open = c.cards.filter(x => !saved[x.key]);
    const shown = c.all ? open : open.filter(x => x.n >= 2).slice(0, 10);
    return `<div class="column" data-column="${i}"><b>${c.title}</b><small>${c.info}</small>` +
      (answered.length ? `<details${c.answeredOpen ? ' open' : ''}><summary>Answered (${answered.length})</summary>${answered.map(x => x.html).join('')}</details>` : '') +
      shown.map(x => x.html).join('') + (open.length > shown.length ? `<button type="button" class="more">${open.length - shown.length} more</button>` : '') + '</div>';
  }).join('');
  mark();
}

function mark() {
  page.querySelectorAll('button[data-key]').forEach(b => {
    const v = saved[b.dataset.key];
    b.classList.toggle('on', 'cycle' in b.dataset ? v == 'normal' || v == 'obscure' : v == b.dataset.value);
    b.classList.toggle('obscure', 'cycle' in b.dataset && v == 'obscure');
  });
}

function save(k, value) {
  if (value) saved[k] = value;
  else delete saved[k];
  const [kind, item, category, other] = JSON.parse(k);
  fetch('receiver.php', {method: 'POST', body: new URLSearchParams({action: 'feedback', seed: data.seed, kind, item, category, other, value})});
  showColumns();
}

// A word's editor opens below its category's words
function editor(w) {
  return `<div class="card"><small>Spelling</small><input data-key="${esc(key('spelling', w, of[w], ''))}" value="${esc(saved[key('spelling', w, of[w], '')] ?? w)}">` +
    `<small>In ${tag(of[w])}</small>${choices(key('membership', w, of[w], ''), membership('Fine', 'Wrong'))}<small>Also belongs to (tap once: counts, twice: obscure)</small>` +
    data.categories.filter(([c]) => c != of[w]).map(([c]) => `<button type="button" class="chip" data-key="${esc(key('membership', w, c, ''))}" data-cycle>${tag(c)}</button>`).join('') + '</div>';
}

document.getElementById('categories').innerHTML = data.categories.map(([c, ws]) => `<details><summary>${tag(c)}</summary>` +
  ws.map(w => `<button type="button" class="chip" data-word="${esc(w)}">${esc(w)}</button>`).join('') + '<div class="editor"></div><small>Keep apart from</small>' +
  data.categories.filter(([d]) => d != c).map(([d]) => `<button type="button" class="chip" data-key="${esc(apart(c, d))}" data-value="1">${tag(d)}</button>`).join('') + '</details>').join('');
page.querySelector('textarea').value = saved[key('text', '', '', '')] ?? '';
showColumns();

page.onclick = e => {
  const b = e.target.closest('button');
  if (!b) return;
  if (b.classList.contains('more')) {
    columns[b.parentNode.dataset.column].all = true;
    return showColumns();
  }
  if (b.dataset.word) {
    b.parentNode.querySelector('.editor').innerHTML = editor(b.dataset.word);
    return mark();
  }
  const v = saved[b.dataset.key];
  save(b.dataset.key, 'cycle' in b.dataset ? {normal: 'obscure', obscure: ''}[v] ?? 'normal' : v == b.dataset.value ? '' : b.dataset.value);
};

// Spelling and free text: a spelling back to the word itself, or an emptied text, takes the answer back
page.onchange = e => {
  const v = e.target.value.trim();
  save(e.target.dataset.key, v == JSON.parse(e.target.dataset.key)[1] ? '' : v);
};
</script>
