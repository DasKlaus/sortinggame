<?php
// Statistics of all sorting games: the raw tables go to the page, which replays each game's attempt log and computes everything
require_once "config.php";

$catIndex = $cats = $games = [];
foreach ($mysql->query("select seed, created_by, language, types, levels, size, items, multiplayer, unix_timestamp(created_at) c, unix_timestamp(finished_at) f from game where mode = 'sorting' order by created_at") as $g)
{
	$items = [];
	foreach (json_decode($g['items']) as [$word, $name])
	{
		$key = "$g[language]|$name";
		if (!isset($catIndex[$key])) { $catIndex[$key] = count($cats); $cats[] = [$g['language'], $name, '']; }
		$items[] = [$word, $catIndex[$key]];
	}
	$games["$g[seed]|$g[created_by]"] = ['seed' => $g['seed'], 'by' => (int)$g['created_by'], 'lang' => $g['language'], 'types' => json_decode($g['types']), 'levels' => json_decode($g['levels'] ?? 'null'), 'n' => (int)$g['size'],
		'mp' => (bool)$g['multiplayer'], 'created' => (int)$g['c'], 'finished' => $g['f'] === null ? null : (int)$g['f'], 'items' => $items, 'att' => []];
}
foreach ($mysql->query("select a.seed, g.created_by, a.user_id, a.a, a.b, a.success, unix_timestamp(a.created_at) t from attempt a join game g on g.seed = a.seed and (g.multiplayer or g.created_by = a.user_id) where g.mode = 'sorting' order by a.id") as $r)
	$games["$r[seed]|$r[created_by]"]['att'][] = [(int)$r['a'], (int)$r['b'], (int)$r['success'], (int)$r['t'], (int)$r['user_id']];
foreach ($mysql->query("select language, name, min(type) from category group by language, name")->fetch_all() as [$lang, $name, $type])
	if (isset($catIndex["$lang|$name"])) $cats[$catIndex["$lang|$name"]][2] = $type;
$names = array_column($mysql->query("select user_id, display_name from player")->fetch_all(), 1, 0);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sorting Game Stats</title>
<link rel="icon" href="favicon.svg">
<style>
:root { color-scheme: light; --bg: #f9f9f7; --surface: #fcfcfb; --ink: #0b0b0b; --ink2: #52514e; --muted: #898781; --border: rgba(11,11,11,.1);
	--s1: #2a78d6; --s2: #eb6834; --s3: #1baf7a; --s4: #eda100; --s5: #e87ba4; --s6: #008300; --s7: #6250d6; --s8: #e34948; }
@media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #0d0d0d; --surface: #1a1a19; --ink: #f0efec; --ink2: #c3c2b7; --muted: #898781; --border: rgba(255,255,255,.1);
	--s1: #3987e5; --s2: #d95926; --s3: #199e70; --s4: #c98500; --s5: #d55181; --s6: #008300; --s7: #9085e9; --s8: #e66767; } }
svg { --plot-background: var(--surface); }
body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1000px; margin: 0 auto; padding: 24px 16px 80px; }
h1 { font-size: 22px; margin: 0 0 4px; }
nav { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin: 16px 0 0; }
nav button { font: inherit; background: none; border: 0; border-bottom: 2px solid transparent; padding: 8px 14px; color: var(--ink2); cursor: pointer; }
nav button.on { color: var(--ink); border-color: var(--ink); font-weight: 600; }
section { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
h2 { font-size: 16px; margin: 0 0 4px; }
h2 span { color: var(--muted); font-weight: normal; margin-right: 8px; }
h3 { font-size: 13px; font-weight: 600; color: var(--ink2); margin: 12px 0 4px; }
.note { color: var(--ink2); margin: 0 0 12px; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.row5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
@media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
table { border-collapse: collapse; font-variant-numeric: tabular-nums; }
td, th { padding: 2px 12px 2px 0; text-align: left; }
td + td, th + th { text-align: right; }
select { font: inherit; max-width: 100%; }
figure { margin: 0; }
.swatch { display: inline-block; width: 12px; height: 12px; border-radius: 2px; vertical-align: -1px; margin: 0 4px 0 8px; }
</style>
</head>
<body>
<main>
<h1>Sorting Game Stats</h1>
<p class="note" id="summary"></p>
<nav><button data-tab="general">General</button><button data-tab="curation">Curation</button><button data-tab="players">Players</button></nav>
</main>
<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@observablehq/plot@0.6.17/dist/plot.umd.min.js"></script>
<script>
const {games, cats, names} = <?php echo json_encode(['games' => array_values($games), 'cats' => $cats, 'names' => $names], JSON_UNESCAPED_UNICODE); ?>;

const css = getComputedStyle(document.documentElement), col = k => css.getPropertyValue('--' + k).trim();
const S = [1, 2, 3, 4, 5, 6, 7, 8].map(i => col('s' + i)), INK = col('ink'), INK2 = col('ink2'), MUTED = col('muted');
const main = document.querySelector('main'), W = main.clientWidth - 42, W2 = W > 700 ? (W - 16) / 2 : W;
const sizes = [5, 10, 15, 20, 25], langs = ['de', 'en'], sizeColor = {domain: sizes, range: S.slice(0, 5), legend: true, label: 'board size'};
const langColor = {domain: langs, range: S.slice(0, 2), legend: true};
const B = 20, bin = x => Math.min(B - 1, Math.floor(x * B)), mid = b => (b + .5) / B;
const progressAxis = {label: 'progress', ticks: [.25, .5, .75], tickFormat: '%'};
const catLabel = c => cats[c][1];
const short = s => s.length > 34 ? s.slice(0, 33) + '…' : s;
const pct = x => (100 * x).toFixed(0) + '%';
const plot = o => Plot.plot({width: W, style: {background: 'transparent', fontSize: '11px'}, ...o});
let pane;
function sec(num, title, note) {
	const s = document.createElement('section');
	s.innerHTML = `<h2><span>${num}</span>${title}</h2><p class="note">${note}</p>`;
	pane.append(s);
	return s;
}
const h3 = text => Object.assign(document.createElement('h3'), {textContent: text});
const cols = (...nodes) => { const d = document.createElement('div'); d.className = 'cols'; d.append(...nodes); return d; };
const box = (...nodes) => { const d = document.createElement('div'); d.append(...nodes); return d; };
const table = (head, rows) => { const t = document.createElement('table'); t.innerHTML = '<tr>' + head.map(h => `<th>${h}</th>`).join('') + '</tr>' + rows.map(r => '<tr>' + r.map(v => `<td>${v}</td>`).join('') + '</tr>').join(''); return t; };
const byLang = f => cols(...langs.map(lang => box(h3(lang == 'de' ? 'Deutsch' : 'English'), f(lang))));
// Groups rows by key, keeps groups of at least min, and shows the top ones by their mean value: a dot per occurrence and a tick at the mean
function ranked(rows, {key, value, label, top = 25, desc = false, min = 3, domain = [0, 1], width = W2}) {
	const groups = d3.groups(rows, key).filter(([, v]) => v.length >= min).map(([k, v]) => ({k, v, m: d3.mean(v, value)}))
		.sort((x, y) => (desc ? y.m - x.m : x.m - y.m) || y.v.length - x.v.length).slice(0, top);
	const pts = groups.flatMap(d => d.v.map(r => ({k: d.k, x: value(r)})));
	return plot({width, height: 30 + groups.length * 16, marginLeft: 220, x: {domain: domain ?? undefined, label, grid: true}, y: {domain: groups.map(d => d.k), label: null, tickFormat: short},
		marks: [Plot.dot(pts, {x: 'x', y: 'k', r: 3, fill: S[0], fillOpacity: .35}),
			Plot.tickX(groups, {x: 'm', y: 'k', stroke: INK, strokeWidth: 2, tip: true, title: d => `${d.k}\nmean ${d.m.toFixed(2)} over ${d.v.length} games`})]});
}

// Replay of every game: cluster heads and sizes at each attempt, per category its clusters and merges, and the guessing onset
const atts = [], occs = [], now = Date.now() / 1000;
for (const g of games) {
	const n = g.n, N = n * n, cat = g.items.map(x => x[1]);
	const head = d3.range(N), members = head.map(i => [i]), last = {};
	const oc = new Map([...new Set(cat)].map(c => [c, {g, c, k: n, big: 0, maxPar: 0, merges: [], fails: 0, disc: null, done: null}]));
	let within = n * n * (n - 1) / 2, K = N, succ = 0, fails = 0;
	g.M = N - n; g.fin = g.finished != null; g.trace = []; g.cum = [0];
	g.att.forEach(([a0, b0, s, t, u], i) => {
		const a = head[a0], b = head[b0], ca = cat[a], cb = cat[b], sa = members[a].length, sb = members[b].length;
		const r = {g, i, s, t, u, a, b, ca, cb, sa, sb, prog: succ / g.M, p: within / (K * (K - 1) / 2), dt: last[u] == null ? null : t - last[u]};
		last[u] = t;
		if (s) {
			const o = oc.get(ca);
			if (o.disc == null) o.disc = i;
			o.big += sa == 1 && sb == 1 ? 1 : sa > 1 && sb > 1 ? -1 : 0;
			o.maxPar = Math.max(o.maxPar, o.big);
			o.merges.push(succ / g.M);
			within -= o.k - 1; o.k--; K--;
			for (const m of members[b]) head[m] = a;
			members[a] = members[a].concat(members[b]);
			succ++;
			if (o.k == 1) o.done = succ;
			g.cum.push(fails);
		} else {
			fails++; oc.get(ca).fails++; oc.get(cb).fails++;
		}
		g.trace.push(r); atts.push(r);
	});
	g.succ = succ; g.fails = fails; g.oc = [...oc.values()];
	g.lastT = g.att.length ? g.att.at(-1)[3] : g.created;
	g.name = g.mp ? 'multiplayer' : names[g.by] || `player ${g.by}`;
	// Guessing onset: the first stretch of 10 attempts whose success rate is at most twice that of random pairs
	g.onset = null;
	for (let i = 9; i < g.trace.length && g.onset == null; i++) {
		const w = g.trace.slice(i - 9, i + 1);
		if (d3.sum(w, r => r.s) / 10 <= 2 * d3.mean(w, r => r.p)) g.onset = i - 9;
	}
	g.onsetProg = g.onset == null ? null : g.trace[g.onset].prog;
	for (const o of g.oc) {
		o.bulk = d3.median(o.merges);
		occs.push(o);
	}
	if (g.fin) [...g.oc].sort((x, y) => x.done - y.done).forEach((o, i) => o.rank = i / (n - 1));
}
const fin = games.filter(g => g.fin), solo = games.filter(g => !g.mp), finOccs = occs.filter(o => o.g.fin);
document.getElementById('summary').textContent = `${games.length} sorting games (${fin.length} finished, ${games.filter(g => g.mp).length} multiplayer), ${atts.length.toLocaleString()} logged attempts, ${new Set(atts.map(r => r.u)).size} players. Progress is the share of a board's merges done (n² − n), so boards of different sizes line up.`;

// Each tab's sections are drawn the first time it is opened
const tabs = {general: [], curation: [], players: []}, panes = {};
const add = (tab, f) => tabs[tab].push(f);
function open(tab) {
	for (const b of document.querySelectorAll('nav button')) b.classList.toggle('on', b.dataset.tab == tab);
	for (const p of Object.values(panes)) p.hidden = true;
	if (!panes[tab]) {
		pane = panes[tab] = document.createElement('div');
		main.append(pane);
		tabs[tab].forEach(f => f());
	}
	panes[tab].hidden = false;
}
for (const b of document.querySelectorAll('nav button')) b.onclick = () => open(b.dataset.tab);

// 1 / 8: error curves
add('general', () => {
	const s = sec('1 / 8', 'Error curve', 'Mistakes made before each correct merge, both per needed merge. Grey: one line per game. Dark: median per board size. The steeper the curve, the more mistakes per merge at that point.');
	const lines = [], meds = [];
	for (const g of games.filter(g => g.M)) for (let k = 0; k <= g.succ; k++) lines.push({g: g.seed + g.by, n: g.n, x: k / g.M, y: g.cum[k] / g.M});
	for (const n of sizes) for (let b = 1; b <= B; b++) {
		const ys = games.filter(g => g.n == n && g.succ >= Math.round(b / B * g.M)).map(g => g.cum[Math.round(b / B * g.M)] / g.M);
		if (ys.length) meds.push({n, x: b / B, y: d3.median(ys), lo: d3.quantile(ys, .25), hi: d3.quantile(ys, .75), count: ys.length});
	}
	s.append(plot({height: 260, fx: {label: 'board size'}, x: progressAxis, y: {label: 'mistakes per merge (cumulative)', grid: true},
		marks: [Plot.line(lines, {x: 'x', y: 'y', z: 'g', fx: 'n', stroke: MUTED, strokeOpacity: .2, strokeWidth: 1}),
			Plot.line(meds, {x: 'x', y: 'y', fx: 'n', stroke: INK, strokeWidth: 2, tip: true, title: d => `${d.n}×${d.n} at ${pct(d.x)}\nmedian ${d.y.toFixed(2)} (IQR ${d.lo.toFixed(2)}–${d.hi.toFixed(2)})\n${d.count} games`})]}));
	const rates = [];
	for (const n of sizes) for (let b = 0; b < B; b++) {
		const ys = games.filter(g => g.n == n && g.succ >= Math.round((b + 1) / B * g.M)).map(g => {
			const k0 = Math.round(b / B * g.M), k1 = Math.round((b + 1) / B * g.M);
			return (g.cum[k1] - g.cum[k0]) / Math.max(1, k1 - k0);
		});
		if (ys.length >= 3) rates.push({n, x: mid(b), y: d3.median(ys), count: ys.length});
	}
	s.append(h3('Local rate: median mistakes per merge within each 5% of progress'), plot({height: 260, color: sizeColor, x: {label: 'progress'}, y: {label: 'mistakes per merge', grid: true},
		marks: [Plot.line(rates, {x: 'x', y: 'y', stroke: 'n', strokeWidth: 2, curve: 'monotone-x', tip: true, title: d => `${d.n}×${d.n} at ${pct(d.x)}: ${d.y.toFixed(2)} (${d.count} games)`})]}));
});

// 17: random baseline and guessing onset
add('general', () => {
	const s = sec('17', 'Skill vs. random pairs', 'Success rate of attempts compared to the chance that a random pair of clusters on the current board matches. The gap is how much players know; where the lines meet, they are guessing. Grey: one line per game. Log scale.');
	const rows = [], lines = [];
	for (const n of sizes) for (const [b, v] of d3.group(atts.filter(r => r.g.n == n), r => bin(r.prog)))
		rows.push({n, x: mid(b), y: d3.mean(v, r => r.s), series: 'players'}, {n, x: mid(b), y: d3.mean(v, r => r.p), series: 'random pairs'});
	for (const g of games) for (const [b, v] of d3.group(g.trace, r => bin(r.prog))) {
		const y = d3.mean(v, r => r.s);
		if (y > 0) lines.push({g: g.seed + g.by, n: g.n, x: mid(b), y});
	}
	s.append(plot({height: 280, fx: {label: 'board size'}, color: {domain: ['players', 'random pairs'], range: [S[0], INK2], legend: true}, x: progressAxis, y: {type: 'log', label: 'success rate', grid: true, tickFormat: '.0%'},
		marks: [Plot.line(lines, {x: 'x', y: 'y', z: 'g', fx: 'n', stroke: MUTED, strokeOpacity: .15, strokeWidth: 1}),
			Plot.line(rows, {x: 'x', y: 'y', stroke: 'series', fx: 'n', strokeWidth: 2, curve: 'monotone-x', tip: true, title: d => `${d.series} at ${pct(d.x)}: ${pct(d.y)}`})]}));
	const on = games.filter(g => g.onset != null);
	s.append(h3('Guessing onset: the first 10 attempts with at most twice the random success rate'), cols(
		plot({width: W2, height: 200, x: {label: 'progress at onset', domain: [0, 1]}, y: {label: 'board size'},
			marks: [Plot.tickX(on, {x: 'onsetProg', y: 'n', stroke: S[1], strokeWidth: 2, tip: true, title: g => `${g.seed} ${g.name}: onset at ${pct(g.onsetProg)}${g.fin ? '' : ', unfinished'}`})]}),
		table(['board size', 'finished games', 'with onset', 'share'], sizes.map(n => { const v = fin.filter(g => g.n == n), k = v.filter(g => g.onset != null).length; return [`${n}×${n}`, v.length, k, v.length ? pct(k / v.length) : '']; }))));
});

// 4: mistakes by size
add('general', () => {
	const s = sec('4', 'Mistakes by board size', 'Finished games only.');
	s.append(cols(
		plot({width: W2, height: 260, x: {label: 'board size'}, y: {label: 'mistakes per needed merge', grid: true}, marks: [Plot.boxY(fin, {x: 'n', y: g => g.fails / g.M, fill: S[0]})]}),
		table(['board size', 'games', 'mean mistakes', 'per needed merge'], sizes.map(n => { const v = fin.filter(g => g.n == n); return [`${n}×${n}`, v.length, d3.mean(v, g => g.fails)?.toFixed(1) ?? '', d3.mean(v, g => g.fails / g.M)?.toFixed(2) ?? '']; }))));
});

// 5: active time
add('general', () => {
	const gone = games.filter(g => !g.fin && now - g.lastT > 2 * 86400).length;
	const s = sec('5', 'Active time', `Finished single-player games. Active time is the sum of the gaps between attempts of at most 2 minutes, which drops all pauses. ${gone} games are abandoned (unfinished, no attempt in the last 2 days).`);
	const active = fin.filter(g => !g.mp).map(g => ({n: g.n, min: d3.sum(g.trace, r => r.dt != null && r.dt <= 120 ? r.dt : 0) / 60}));
	s.append(cols(
		plot({width: W2, height: 240, x: {label: 'board size'}, y: {label: 'active minutes', grid: true}, marks: [Plot.boxY(active, {x: 'n', y: 'min', fill: S[0]})]}),
		table(['board size', 'games', 'median minutes'], sizes.map(n => { const v = active.filter(a => a.n == n); return [`${n}×${n}`, v.length, v.length ? d3.median(v, a => a.min).toFixed(0) : '']; }))));
});

// 3: settings
add('general', () => {
	const s = sec('3', 'Game settings', 'Finished games only, including copies of shared single-player boards.');
	const top5 = (counts, desc = true) => [...counts].map(([k, [x, n]]) => [k, x, n]).sort((a, b) => desc ? b[1] / b[2] - a[1] / a[2] || b[2] - a[2] : a[1] / a[2] - b[1] / b[2] || b[2] - a[2]).slice(0, 5).map(([k, x, n]) => [k, `${x} of ${n}`, pct(x / n)]);
	const inc = (m, k, hit) => { const c = m.get(k) || [0, 0]; c[0] += hit; c[1]++; m.set(k, c); };
	// A type counts as offered in a game when games of its language selected it both before and after, which leaves out renamed and new types
	const seen = new Map();
	for (const g of games) for (const t of g.types) { const k = g.lang + '|' + t; seen.set(k, [seen.get(k)?.[0] ?? g.created, g.created]); }
	const few = new Map(), deselected = new Map();
	for (const g of fin) {
		if (g.types.length <= 3) for (const t of g.types) inc(few, t, 1);
		else for (const [k, [t0, t1]] of seen) if (k.startsWith(g.lang + '|') && t0 <= g.created && g.created <= t1) inc(deselected, k.slice(3), !g.types.includes(k.slice(3)));
	}
	const nFew = fin.filter(g => g.types.length <= 3).length;
	s.append(cols(
		box(h3('Board sizes'), plot({width: W2, height: 220, color: langColor, x: {label: 'board size'}, y: {label: 'games', grid: true}, marks: [Plot.barY(fin, Plot.groupX({y: 'count'}, {x: 'n', fill: 'lang', tip: true}))]})),
		box(h3(`Top types in games with 1–3 types (${nFew} games)`), table(['type', 'games', 'share'], [...few].sort((a, b) => b[1][0] - a[1][0]).slice(0, 5).map(([k, [x]]) => [k, x, pct(x / nFew)])),
			h3('Most often deselected in games with 4+ types'), table(['type', 'deselected', 'share'], top5(deselected)))));
	const easy = new Map(), hard = new Map(), withLevels = fin.filter(g => g.levels);
	for (const g of withLevels) for (const [topic, level] of Object.entries(g.levels)) { inc(easy, topic, level == 1); inc(hard, topic, level == 3); }
	s.append(h3(`Topic levels (${withLevels.length} games with topics; share of games that set the topic to the level)`), cols(
		box(h3('Most often easy'), table(['topic', 'games', 'share'], top5(easy)), h3('Least often easy'), table(['topic', 'games', 'share'], top5(easy, false))),
		box(h3('Most often hard'), table(['topic', 'games', 'share'], top5(hard)), h3('Least often hard'), table(['topic', 'games', 'share'], top5(hard, false)))));
});

// 16: merge kinds
add('general', () => {
	const s = sec('16', 'Kinds of merges over the game', 'What was combined, by cluster sizes before the attempt. "First" is the first click. Shares within each 5% of progress.');
	const kinds = ['single + single', 'cluster first, then single', 'single first, then cluster', 'cluster + cluster'];
	const kind = r => r.sa == 1 && r.sb == 1 ? kinds[0] : r.sa > 1 && r.sb > 1 ? kinds[3] : r.sb > 1 ? kinds[1] : kinds[2];
	const rows = ok => sizes.flatMap(n => { const c = d3.rollup(atts.filter(r => r.s == ok && r.g.n == n), v => v.length, r => bin(r.prog), kind); return d3.range(B).flatMap(b => kinds.map(k => ({n, x: mid(b), kind: k, count: c.get(b)?.get(k) || 0}))); });
	const color = {domain: kinds, range: S.slice(0, 4), legend: true};
	for (const [ok, title] of [[1, 'Correct merges'], [0, 'Mistakes']])
		s.append(h3(title), plot({height: 220, color, fx: {label: 'board size'}, x: progressAxis, y: {label: 'share', tickFormat: '%'},
			marks: [Plot.areaY(rows(ok), {x: 'x', y: 'count', fx: 'n', fill: 'kind', order: kinds, offset: 'normalize', curve: 'monotone-x', tip: true, title: d => `${d.kind} at ${pct(d.x)}: ${d.count}`})]}));
});

// 18: probing runs
add('general', () => {
	const s = sec('18', 'Probing runs', 'Three or more mistakes in a row that share one cluster: either a cluster tried against many others, or a single item tried against many. Single-player games. Found: the next attempt merges the shared cluster. Tentative: run lengths depend on how many clusters are left to try.');
	const runs = [];
	for (const g of solo) {
		let run = null;
		const close = found => {
			if (run && run.rs.length >= 3) {
				const f = run.set[0], first = run.rs[0], firstClick = run.rs.filter(r => r.b == f).length;
				runs.push({prog: first.prog, len: run.rs.length, found, kind: `${(f == first.a ? first.sa : first.sb) > 1 ? 'cluster' : 'single'}, ${firstClick == run.rs.length ? 'clicked first' : firstClick ? 'mixed' : 'clicked second'}`});
			}
			run = null;
		};
		for (const r of g.trace) {
			if (r.s) { close(!!run && (run.set.includes(r.a) || run.set.includes(r.b))); continue; }
			const set = run && run.set.filter(x => x == r.a || x == r.b);
			if (set?.length) { run.set = set; run.rs.push(r); }
			else { close(false); run = {set: [r.a, r.b], rs: [r]}; }
		}
		close(false);
	}
	const kinds = ['cluster, clicked first', 'cluster, clicked second', 'cluster, mixed', 'single, clicked first', 'single, clicked second', 'single, mixed'];
	s.append(cols(
		plot({width: W2, height: 240, color: {domain: kinds, range: S.slice(0, 6), legend: true}, x: {label: 'progress at start', domain: [0, 1]}, y: {label: 'runs', grid: true},
			marks: [Plot.rectY(runs, Plot.binX({y: 'count'}, {x: 'prog', fill: 'kind', order: kinds, thresholds: d3.range(0, 1.001, .05), tip: true}))]}),
		table(['kind', 'runs', 'median length', 'found'], kinds.map(k => { const v = runs.filter(r => r.kind == k); return [k, v.length, v.length ? d3.median(v, r => r.len) : '', v.length ? pct(d3.mean(v, r => r.found)) : '']; }))));
});

// 10: parallel clusters
add('general', () => {
	const s = sec('10', 'Parallel clusters', 'Most clusters of 2+ items a category had at the same time before they were merged into one; 1 means it was built as a single growing cluster. Finished single-player games.');
	s.append(plot({height: 240, fx: {label: 'board size'}, x: {label: null}, y: {label: 'share of categories', tickFormat: '%', grid: true},
		marks: [Plot.barY(finOccs.filter(o => !o.g.mp), Plot.groupX({y: 'proportion-facet'}, {x: 'maxPar', fx: o => o.g.n, fill: S[0], tip: true}))]}));
});

// 9: category timing
add('curation', () => {
	const s = sec('9', 'Categories finished early or late', 'Completion rank: order in which a board\'s categories are completed, 0 = first, 1 = last. Bulk: median progress of the category\'s merges. Categories in at least 3 finished games; dots are games, ticks the mean.');
	const of = lang => finOccs.filter(o => cats[o.c][0] == lang);
	for (const [title, value, label, desc] of [['Completed earliest', o => o.rank, 'completion rank', false], ['Completed latest', o => o.rank, 'completion rank', true],
		['Bulk of merges earliest', o => o.bulk, 'median merge progress', false], ['Bulk of merges latest', o => o.bulk, 'median merge progress', true]])
		s.append(h3(title), byLang(lang => ranked(of(lang), {key: o => catLabel(o.c), value, label, desc, top: 20})));
});

// 11 / 23: top confusions
add('curation', () => {
	const s = sec('11 / 23', 'Top confusions', 'Mistakes between two categories compared to the number expected if mistakes paired categories at random, given how often each of the two was involved in that game\'s mistakes. Only games where both were on the board count, so board size and category difficulty are factored out. Each board counts once: games on the same board are averaged. Pairs confused on 2+ boards.');
	const obs = new Map(), exp = new Map(), raw = new Map(), boards = new Map(), key = (x, y) => x < y ? x + ',' + y : y + ',' + x;
	const perSeed = d3.rollup(games, v => v.length, g => g.seed);
	for (const g of games.filter(g => g.fails)) {
		const inv = new Map(), w = 1 / perSeed.get(g.seed);
		for (const r of g.trace.filter(r => !r.s)) {
			for (const c of [r.ca, r.cb]) inv.set(c, (inv.get(c) || 0) + 1);
			const k = key(r.ca, r.cb);
			obs.set(k, (obs.get(k) || 0) + w);
			raw.set(k, (raw.get(k) || 0) + 1);
			boards.set(k, (boards.get(k) || new Set()).add(g.seed));
		}
		const cs = [...inv.keys()];
		for (let i = 0; i < cs.length; i++) for (let j = i + 1; j < cs.length; j++) {
			const k = key(cs[i], cs[j]);
			exp.set(k, (exp.get(k) || 0) + w * inv.get(cs[i]) * inv.get(cs[j]) / (2 * g.fails));
		}
	}
	const all = [...boards].filter(([, v]) => v.size >= 2).map(([k]) => { const [x, y] = k.split(',').map(Number); return {lang: cats[x][0], k: `${catLabel(x)} ↔ ${catLabel(y)}`, lift: obs.get(k) / exp.get(k), raw: raw.get(k), boards: boards.get(k).size}; });
	s.append(byLang(lang => { const top = all.filter(d => d.lang == lang).sort((x, y) => y.lift - x.lift).slice(0, 20);
		return plot({width: W2, height: 40 + top.length * 16, marginLeft: 260, x: {label: 'mistakes / expected', grid: true}, y: {label: null, tickFormat: s => s.length > 44 ? s.slice(0, 43) + '…' : s},
			marks: [Plot.barX(top, {x: 'lift', y: 'k', fill: S[1], sort: {y: '-x'}, tip: true, title: d => `${d.k}\n${d.lift.toFixed(1)}× expected\n${d.raw} mistakes on ${d.boards} boards`}), Plot.ruleX([1], {stroke: MUTED})]}); }));
});

// 12: mistakes by type
add('curation', () => {
	const s = sec('12', 'Mistakes by category type', 'Mistakes involving a category of the type (each mistake counts for both its categories) per merge made in categories of that type. Finished games.');
	const rows = d3.flatRollup(finOccs, v => ({fails: d3.sum(v, o => o.fails), merges: d3.sum(v, o => o.merges.length), count: v.length}), o => cats[o.c][2] || '(not in category table)', o => cats[o.c][0])
		.map(([type, lang, v]) => ({type, lang, ...v, y: v.fails / v.merges}));
	s.append(byLang(lang => { const v = rows.filter(r => r.lang == lang);
		return plot({width: W2, height: 30 + v.length * 18, marginLeft: 150, x: {label: 'mistakes per merge', grid: true}, y: {label: null},
			marks: [Plot.barX(v, {x: 'y', y: 'type', fill: S[0], sort: {y: '-x'}, tip: true, title: d => `${d.type}: ${d.y.toFixed(2)}\n${d.fails} mistakes, ${d.merges} merges, ${d.count} category occurrences`})]}); }));
});

// 19: categories found by guessing
add('curation', () => {
	const s = sec('19', 'Categories found by guessing', 'The first merge of a category came after the game\'s guessing onset (see 17), or right after 2+ mistakes involving one of the two items. Categories in 3+ games.');
	const rows = occs.filter(o => o.disc != null).map(o => {
		const t = o.g.trace, d = o.disc, r = t[d], prev = t.slice(Math.max(0, d - 2), d);
		return {o, guessed: (o.g.onset != null && d >= o.g.onset) || (prev.length == 2 && prev.every(p => !p.s && [p.a, p.b].some(x => x == r.a || x == r.b)))};
	});
	s.querySelector('.note').textContent += ` Overall ${pct(d3.mean(rows, r => r.guessed))} of category discoveries.`;
	s.append(byLang(lang => { const top = d3.groups(rows.filter(r => cats[r.o.c][0] == lang), r => catLabel(r.o.c)).filter(([, v]) => v.length >= 3).map(([k, v]) => ({k, y: d3.mean(v, r => r.guessed), count: v.length}))
			.sort((x, y) => y.y - x.y || y.count - x.count).slice(0, 20);
		return plot({width: W2, height: 40 + top.length * 16, marginLeft: 220, x: {label: 'share found by guessing', tickFormat: '%', domain: [0, 1], grid: true}, y: {label: null, tickFormat: short},
			marks: [Plot.barX(top, {x: 'y', y: 'k', fill: S[1], sort: {y: '-x'}, tip: true, title: d => `${d.k}: ${pct(d.y)} of ${d.count} games`})]}); }));
});

// 7: single game
add('players', () => {
	const s = sec('7', 'Single game', 'One game in detail: success rate over the last 10 attempts against the random-pair rate, and each category\'s merges and mistakes in attempt order. The orange line is the computed guessing onset.');
	const select = document.createElement('select'), out = document.createElement('div'), list = [...games].filter(g => g.att.length).sort((x, y) => y.created - x.created);
	select.innerHTML = list.map((g, i) => `<option value="${i}">${new Date(g.created * 1000).toLocaleDateString()} · ${g.seed} · ${g.n}×${g.n} ${g.lang} · ${g.name} · ${g.fails} mistakes${g.fin ? '' : ' · unfinished'}</option>`).join('');
	select.value = list.findIndex(g => g.fin && g.n >= 10);
	const show = () => {
		const g = list[select.value], t = g.trace, roll = t.slice(9).map((r, i) => ({i: i + 9, players: d3.mean(t.slice(i, i + 10), r => r.s), random: d3.mean(t.slice(i, i + 10), r => r.p)}));
		const word = i => g.items[i][0], order = [...g.oc].sort((x, y) => (x.done ?? 1e9) - (y.done ?? 1e9)).map(o => catLabel(o.c));
		const ev = t.flatMap(r => (r.s ? [r.ca] : [r.ca, r.cb]).map(c => ({i: r.i, c: catLabel(c), kind: r.s ? 'merge' : 'mistake', text: `#${r.i} ${word(r.b)} (${r.sb}) → ${word(r.a)} (${r.sa})${r.s ? '' : ' ✗'}`})));
		const onset = g.onset == null ? [] : [Plot.ruleX([g.onset], {stroke: S[1], strokeWidth: 2}), Plot.text([g.onset], {x: d => d, frameAnchor: 'top', text: () => 'guessing onset', textAnchor: 'start', dx: 4, dy: 2, fill: INK2})];
		out.replaceChildren(
			plot({height: 220, color: {domain: ['players', 'random'], range: [S[0], INK2], legend: true}, x: {label: 'attempt'}, y: {label: 'success rate', grid: true, domain: [0, 1]},
				marks: [...onset, Plot.line(roll, {x: 'i', y: 'players', stroke: S[0], strokeWidth: 2}), Plot.line(roll, {x: 'i', y: 'random', stroke: INK2, strokeWidth: 2}),
					Plot.tip(roll, Plot.pointerX({x: 'i', y: 'players', title: d => `#${d.i}: ${pct(d.players)} vs. ${pct(d.random)} random`}))]}),
			plot({height: 40 + order.length * 15, marginLeft: 200, color: {domain: ['merge', 'mistake'], range: S.slice(0, 2), legend: true}, x: {label: 'attempt', grid: true}, y: {domain: order, label: null, tickFormat: short},
				marks: [...onset, Plot.dot(ev, {x: 'i', y: 'c', fill: 'kind', r: 3, tip: true, title: 'text'})]}));
	};
	select.onchange = show;
	s.append(select, out);
	show();
});

// 15: learning curves
add('players', () => {
	const s = sec('15', 'Learning curves', `Mistakes per needed merge of each finished single-player game, in the order finished, per board size. Signed-in players with 3+ finished games; same y scale everywhere, x scaled per board size. <br>Background: finished<span class="swatch" style="background:${S[3]};opacity:.5"></span>in the last 24 hours<span class="swatch" style="background:${S[3]};opacity:.2"></span>in the last 7 days.`);
	const per = d3.groups(fin.filter(g => !g.mp && g.by), g => g.by).filter(([, v]) => v.length >= 3).sort((x, y) => y[1].length - x[1].length);
	const max = d3.max(per, ([, v]) => d3.max(v, g => g.fails / g.M));
	for (const [by, v] of per) {
		const row = document.createElement('div');
		row.className = 'row5';
		for (const n of sizes) {
			const pts = v.filter(g => g.n == n).sort((a, b) => a.finished - b.finished).map((g, i) => ({g, i, y: g.fails / g.M, age: now - g.finished}));
			const band = (maxAge, minAge, opacity) => Plot.barX(pts.filter(d => d.age < maxAge && d.age >= minAge), {x1: d => d.i - .5, x2: d => d.i + .5, fill: S[3], fillOpacity: opacity});
			row.append(plot({width: (W - 32) / 5, height: 120, marginTop: 20, x: {axis: null, domain: [-.5, Math.max(1, pts.length) - .5]}, y: {domain: [0, max], ticks: 3, grid: true, label: null},
				marks: [Plot.text([`${n}×${n} (${pts.length})`], {frameAnchor: 'top', dy: -14, fill: INK2}), band(86400, 0, .5), band(7 * 86400, 86400, .2), Plot.line(pts, {x: 'i', y: 'y', stroke: S[0], strokeWidth: 1.5}),
					Plot.dot(pts, {x: 'i', y: 'y', fill: S[0], r: 2.5, tip: true, title: d => `${new Date(d.g.finished * 1000).toLocaleDateString()} ${n}×${n} ${d.g.lang}: ${d.g.fails} mistakes`})]}));
		}
		s.append(h3(`${names[by] || 'player ' + by} (${v.length})`), row);
	}
});

// 25: same board, several players
add('players', () => {
	const s = sec('25', 'Same board, several players', 'Single-player boards played by 2+ players: mistakes made before each correct merge.');
	const grid = document.createElement('div');
	grid.className = 'cols';
	for (const [seed, v] of d3.groups(solo.filter(g => g.att.length), g => g.seed).filter(([, v]) => v.length >= 2)) {
		const pts = v.flatMap(g => g.cum.map((y, x) => ({x, y, by: g.by, name: g.name + (g.fin ? '' : ' (unfinished)')})));
		grid.append(box(h3(`${seed} · ${v[0].n}×${v[0].n} ${v[0].lang}`), plot({width: W2, height: 180, color: {legend: true, range: S}, x: {label: 'correct merges'}, y: {label: 'mistakes', grid: true},
			marks: [Plot.line(pts, {x: 'x', y: 'y', z: 'by', stroke: 'name', strokeWidth: 1.5, tip: true})]})));
	}
	s.append(grid);
});

// 26: multiplayer
add('players', () => {
	const s = sec('26', 'Multiplayer shares', 'Correct merges and mistakes per player in each multiplayer game with 2+ players, both per needed merge of the board; colour is the player\'s rank by merges within the game.');
	const rows = games.filter(g => g.mp && new Set(g.att.map(r => r[4])).size > 1).flatMap(g => {
		const label = `${g.seed} · ${g.n}×${g.n}`, per = d3.rollups(g.trace, v => ({merges: d3.sum(v, r => r.s), mistakes: d3.sum(v, r => !r.s)}), r => r.u).sort((x, y) => y[1].merges - x[1].merges);
		return per.flatMap(([u, v], i) => [['merges', v.merges], ['mistakes', v.mistakes]].map(([measure, x]) => ({label, measure, x: x / g.M, raw: x, rank: String(i + 1), name: names[u] || 'player ' + u})));
	});
	const ranks = [...new Set(rows.map(r => r.rank))].sort((x, y) => x - y);
	s.append(plot({height: 40 + new Set(rows.map(r => r.label)).size * 22, marginLeft: 130, fx: {label: null}, color: {domain: ranks, range: S, legend: true, label: 'rank'}, x: {grid: true, label: 'per needed merge'}, y: {label: null},
		marks: [Plot.barX(rows, {x: 'x', y: 'label', fx: 'measure', fill: 'rank', order: ranks, tip: true, title: d => `${d.name}: ${d.raw} ${d.measure} (${d.x.toFixed(2)} per needed merge)`})]}));
});

open('general');
</script>
</body>
</html>
