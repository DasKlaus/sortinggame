<?php
// Statistics of all sorting games: the raw tables go to the page, which replays each game's attempt log and computes everything
require_once "config.php";

$catIndex = $cats = $games = $obscure = $feedback = [];
foreach ($mysql->query("select seed, created_by, language, types, size, items, multiplayer, unix_timestamp(created_at) c, unix_timestamp(finished_at) f from game where mode = 'sorting' order by created_at") as $g)
{
	$items = [];
	foreach (json_decode($g['items']) as [$word, $name])
	{
		$key = "$g[language]|$name";
		if (!isset($catIndex[$key])) { $catIndex[$key] = count($cats); $cats[] = [$g['language'], $name, '']; }
		$items[] = [$word, $catIndex[$key]];
	}
	$games["$g[seed]|$g[created_by]"] = ['seed' => $g['seed'], 'by' => (int)$g['created_by'], 'lang' => $g['language'], 'types' => json_decode($g['types']), 'n' => (int)$g['size'],
		'mp' => (bool)$g['multiplayer'], 'created' => (int)$g['c'], 'finished' => $g['f'] === null ? null : (int)$g['f'], 'items' => $items, 'att' => []];
}
foreach ($mysql->query("select a.seed, g.created_by, a.user_id, a.a, a.b, a.success, unix_timestamp(a.created_at) t from attempt a join game g on g.seed = a.seed and (g.multiplayer or g.created_by = a.user_id) where g.mode = 'sorting' order by a.id") as $r)
	$games["$r[seed]|$r[created_by]"]['att'][] = [(int)$r['a'], (int)$r['b'], (int)$r['success'], (int)$r['t'], (int)$r['user_id']];
foreach ($mysql->query("select language, name, min(type) from category group by language, name")->fetch_all() as [$lang, $name, $type])
	if (isset($catIndex["$lang|$name"])) $cats[$catIndex["$lang|$name"]][2] = $type;
foreach ($mysql->query("select c.language, c.name, m.item from membership m join category c on c.id = m.category_id where m.level = 4")->fetch_all() as [$lang, $name, $item])
	if (isset($catIndex["$lang|$name"])) $obscure[] = [$catIndex["$lang|$name"], $item];
foreach ($mysql->query("select (select language from game where seed = f.seed limit 1), category, item, value, count(*) from feedback f where kind = 'membership' group by 1, 2, 3, 4")->fetch_all() as [$lang, $name, $item, $value, $n])
	if (isset($catIndex["$lang|$name"])) $feedback[] = [$catIndex["$lang|$name"], $item, $value, (int)$n];
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
:root { color-scheme: light; --bg: #f9f9f7; --surface: #fcfcfb; --ink: #0b0b0b; --ink2: #52514e; --muted: #898781; --border: rgba(11,11,11,.1); --plot-background: #fcfcfb;
	--s1: #2a78d6; --s2: #eb6834; --s3: #1baf7a; --s4: #eda100; --s5: #e87ba4; --s6: #008300; --s7: #6250d6; --s8: #e34948; }
@media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #0d0d0d; --surface: #1a1a19; --ink: #f0efec; --ink2: #c3c2b7; --muted: #898781; --border: rgba(255,255,255,.1); --plot-background: #1a1a19;
	--s1: #3987e5; --s2: #d95926; --s3: #199e70; --s4: #c98500; --s5: #d55181; --s6: #008300; --s7: #9085e9; --s8: #e66767; } }
body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1000px; margin: 0 auto; padding: 24px 16px 80px; }
h1 { font-size: 22px; margin: 0 0 4px; }
section { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
h2 { font-size: 16px; margin: 0 0 4px; }
h2 span { color: var(--muted); font-weight: normal; margin-right: 8px; }
h3 { font-size: 13px; font-weight: 600; color: var(--ink2); margin: 12px 0 4px; }
.note { color: var(--ink2); margin: 0 0 12px; max-width: 80ch; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
@media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
table { border-collapse: collapse; font-variant-numeric: tabular-nums; }
td, th { padding: 2px 12px 2px 0; text-align: left; }
td + td { text-align: right; }
select { font: inherit; max-width: 100%; }
figure { margin: 0; }
</style>
</head>
<body>
<main>
<h1>Sorting Game Stats</h1>
<p class="note" id="summary"></p>
</main>
<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@observablehq/plot@0.6.17/dist/plot.umd.min.js"></script>
<script>
const {games, cats, obscure, feedback, names} = <?php echo json_encode(['games' => array_values($games), 'cats' => $cats, 'obscure' => $obscure, 'feedback' => $feedback, 'names' => $names], JSON_UNESCAPED_UNICODE); ?>;

const css = getComputedStyle(document.documentElement), col = k => css.getPropertyValue('--' + k).trim();
const S = [1, 2, 3, 4, 5, 6, 7, 8].map(i => col('s' + i)), INK = col('ink'), MUTED = col('muted');
const main = document.querySelector('main'), W = main.clientWidth - 42, W2 = W > 700 ? (W - 16) / 2 : W;
const sizes = [5, 10, 15, 20, 25], sizeColor = {domain: sizes, range: S.slice(0, 5), legend: true, label: 'board size'};
const langColor = {domain: ['de', 'en'], range: S.slice(0, 2), legend: true};
const B = 20, bin = x => Math.min(B - 1, Math.floor(x * B)), mid = b => (b + .5) / B;
const catLabel = c => cats[c][1] + (cats[c][0] == 'de' ? '' : ' (en)');
const short = s => s.length > 34 ? s.slice(0, 33) + '…' : s;
const pct = x => (100 * x).toFixed(0) + '%';
const plot = o => Plot.plot({width: W, style: {background: 'transparent', fontSize: '11px'}, ...o});
function sec(num, title, note) {
	const s = document.createElement('section');
	s.innerHTML = `<h2><span>${num}</span>${title}</h2><p class="note">${note}</p>`;
	main.append(s);
	return s;
}
const h3 = text => Object.assign(document.createElement('h3'), {textContent: text});
const cols = (...nodes) => { const d = document.createElement('div'); d.className = 'cols'; d.append(...nodes); return d; };
const box = (...nodes) => { const d = document.createElement('div'); d.append(...nodes); return d; };
const table = (head, rows) => { const t = document.createElement('table'); t.innerHTML = '<tr>' + head.map(h => `<th>${h}</th>`).join('') + '</tr>' + rows.map(r => '<tr>' + r.map(v => `<td>${v}</td>`).join('') + '</tr>').join(''); return t; };
// Groups rows by key, keeps groups of at least min, and shows the top ones by their mean value: a dot per occurrence and a tick at the mean
function ranked(rows, {key, value, label, top = 25, desc = false, min = 3, domain = [0, 1], width = W2}) {
	const groups = d3.groups(rows, key).filter(([, v]) => v.length >= min).map(([k, v]) => ({k, v, m: d3.mean(v, value)}))
		.sort((x, y) => (desc ? y.m - x.m : x.m - y.m) || y.v.length - x.v.length).slice(0, top);
	const pts = groups.flatMap(d => d.v.map(r => ({k: d.k, x: value(r)})));
	return plot({width, height: 30 + groups.length * 16, marginLeft: 220, x: {domain: domain ?? undefined, label, grid: true}, y: {domain: groups.map(d => d.k), label: null, tickFormat: short},
		marks: [Plot.dot(pts, {x: 'x', y: 'k', r: 3, fill: S[0], fillOpacity: .35}),
			Plot.tickX(groups, {x: 'm', y: 'k', stroke: INK, strokeWidth: 2, tip: true, title: d => `${d.k}\nmean ${d.m.toFixed(2)} over ${d.v.length} games`})]});
}

// Replay of every game: cluster heads and sizes at each attempt, per category its clusters, merges and mistakes, per item when it stopped being single
const atts = [], occs = [], itemOccs = [], now = Date.now() / 1000;
for (const g of games) {
	const n = g.n, N = n * n, cat = g.items.map(x => x[1]);
	const head = d3.range(N), members = head.map(i => [i]), joined = Array(N).fill(null), failsSingle = Array(N).fill(0), last = {};
	const oc = new Map([...new Set(cat)].map(c => [c, {g, c, k: n, big: 0, maxPar: 0, merges: [], fails: 0, disc: null, done: null}]));
	let within = n * n * (n - 1) / 2, K = N, succ = 0, fails = 0;
	g.M = N - n; g.fin = g.finished != null; g.trace = []; g.cum = [0];
	g.att.forEach(([a0, b0, s, t, u], i) => {
		const a = head[a0], b = head[b0], ca = cat[a], cb = cat[b], sa = members[a].length, sb = members[b].length;
		const r = {g, i, s, t, u, a, b, ca, cb, sa, sb, prog: succ / g.M, p: within / (K * (K - 1) / 2), dt: last[u] == null ? null : t - last[u]};
		last[u] = t;
		if (s) {
			const o = oc.get(ca);
			if (sa == 1) joined[a] = succ;
			if (sb == 1) joined[b] = succ;
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
			if (sa == 1) failsSingle[a]++;
			if (sb == 1) failsSingle[b]++;
		}
		g.trace.push(r); atts.push(r);
	});
	g.succ = succ; g.fails = fails; g.oc = [...oc.values()];
	g.lastT = g.att.length ? g.att.at(-1)[3] : g.created;
	g.name = g.mp ? 'multiplayer' : names[g.by] || 'guest';
	// Guessing onset: the first stretch of 10 attempts whose success rate is at most twice that of random pairs
	g.onset = null;
	for (let i = 9; i < g.trace.length && g.onset == null; i++) {
		const w = g.trace.slice(i - 9, i + 1);
		if (d3.sum(w, r => r.s) / 10 <= 2 * d3.mean(w, r => r.p)) g.onset = i - 9;
	}
	g.onsetProg = g.onset == null ? null : g.trace[g.onset].prog;
	for (const o of g.oc) {
		o.bulk = d3.median(o.merges);
		o.pattern = o.merges[Math.ceil((n - 1) / 3) - 1];
		o.complete = o.merges[n - 2];
		o.perMerge = o.merges.length ? o.fails / o.merges.length : null;
		occs.push(o);
	}
	if (g.fin) {
		[...g.oc].sort((x, y) => x.done - y.done).forEach((o, i) => o.rank = i / (n - 1));
		for (const o of g.oc) {
			const its = d3.range(N).filter(i => cat[i] == o.c).sort((x, y) => joined[x] - joined[y]);
			its.forEach(i => itemOccs.push({g, c: o.c, item: g.items[i][0], key: `${g.items[i][0]} · ${catLabel(o.c)}`,
				rank: its.findIndex(j => joined[j] == joined[i]) / (n - 1), fs: failsSingle[i]}));
		}
	}
}
const fin = games.filter(g => g.fin), solo = games.filter(g => !g.mp), finOccs = occs.filter(o => o.g.fin);
document.getElementById('summary').textContent = `${games.length} sorting games (${fin.length} finished, ${games.filter(g => g.mp).length} multiplayer), ${atts.length.toLocaleString()} logged attempts, ${new Set(atts.map(r => r.u)).size} players. Progress is the share of a board's merges done (n² − n), so boards of different sizes line up.`;

// 1 / 8: error curves
{
	const s = sec('1 / 8', 'Error curve', 'Mistakes made before each correct merge, both per needed merge. Grey: one line per game. Dark: median per board size. The steeper the curve, the more mistakes per merge at that point.');
	const lines = [], meds = [];
	for (const g of games.filter(g => g.M)) for (let k = 0; k <= g.succ; k++) lines.push({g: g.seed + g.by, n: g.n, x: k / g.M, y: g.cum[k] / g.M});
	for (const n of sizes) for (let b = 1; b <= B; b++) {
		const ys = games.filter(g => g.n == n && g.succ >= Math.round(b / B * g.M)).map(g => g.cum[Math.round(b / B * g.M)] / g.M);
		if (ys.length) meds.push({n, x: b / B, y: d3.median(ys), lo: d3.quantile(ys, .25), hi: d3.quantile(ys, .75), count: ys.length});
	}
	s.append(plot({height: 260, fx: {label: 'board size'}, x: {label: 'progress', ticks: [.25, .5, .75], tickFormat: '%'}, y: {label: 'mistakes per merge (cumulative)', grid: true},
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
}

// 2 / 13: quick attempts
{
	const s = sec('2 / 13', 'Quick attempts', 'Seconds since the same player\'s previous attempt in the game, only gaps up to 2 minutes (pauses dropped).');
	const quick = atts.filter(r => r.dt != null && r.dt <= 120);
	const dist = [];
	for (const [ok, label] of [[1, 'correct'], [0, 'mistake']]) {
		const rs = quick.filter(r => r.s == ok), bins = d3.bin().domain([0, 60]).thresholds(d3.range(0, 61, 1))(rs.filter(r => r.dt <= 60).map(r => r.dt));
		for (const b of bins) dist.push({x: (b.x0 + b.x1) / 2, y: b.length / rs.length, label});
	}
	const rate = d3.bin().domain([0, 120]).thresholds(d3.range(0, 121, 4)).value(r => r.dt)(quick).filter(b => b.length >= 20)
		.map(b => ({x: (b.x0 + b.x1) / 2, y: d3.mean(b, r => 1 - r.s), count: b.length}));
	s.querySelector('.note').textContent += ` ${pct(quick.length / atts.filter(r => r.dt != null).length)} of attempts follow within 2 minutes.`;
	s.append(cols(
		box(h3('Distribution of the gap before a correct merge vs. a mistake (up to 60 s)'), plot({width: W2, height: 240, color: {domain: ['correct', 'mistake'], range: S.slice(0, 2), legend: true}, x: {label: 'seconds'}, y: {label: 'share', tickFormat: '%', grid: true},
			marks: [Plot.line(dist, {x: 'x', y: 'y', stroke: 'label', strokeWidth: 2, curve: 'monotone-x', tip: true, title: d => `${d.label}, ${d.x}s: ${pct(d.y)}`})]})),
		box(h3('Share of mistakes by gap'), plot({width: W2, height: 240, x: {label: 'seconds'}, y: {label: 'mistakes', tickFormat: '%', grid: true, domain: [0, 1]},
			marks: [Plot.line(rate, {x: 'x', y: 'y', stroke: S[0], strokeWidth: 2, curve: 'monotone-x'}), Plot.dot(rate, {x: 'x', y: 'y', fill: S[0], r: 3, tip: true, title: d => `${d.x}s: ${pct(d.y)} mistakes of ${d.count}`})]}))));
}

// 3: settings
{
	const s = sec('3', 'Game settings', 'What players choose when creating a game: category types and board sizes, per language. Every game row counts, including copies of shared single-player boards.');
	const types = games.flatMap(g => g.types.map(type => ({type, lang: g.lang})));
	s.append(cols(
		box(h3('Category types selected'), plot({width: W2, height: 30 + new Set(types.map(t => t.type)).size * 18, marginLeft: 110, color: langColor, x: {label: 'games', grid: true}, y: {label: null},
			marks: [Plot.barX(types, Plot.groupY({x: 'count'}, {y: 'type', fill: 'lang', sort: {y: 'x', reverse: true}, tip: true}))]})),
		box(h3('Board sizes'), plot({width: W2, height: 240, color: langColor, x: {label: 'board size'}, y: {label: 'games', grid: true},
			marks: [Plot.barY(games, Plot.groupX({y: 'count'}, {x: 'n', fill: 'lang', tip: true}))]}),
			h3('Types selected per game'), plot({width: W2, height: 160, x: {label: 'types'}, y: {label: 'games', grid: true},
			marks: [Plot.barY(games, Plot.groupX({y: 'count'}, {x: g => g.types.length, fill: S[0], tip: true}))]}))));
}

// 4: mistakes by size
{
	const s = sec('4', 'Mistakes by board size', 'Finished games only.');
	s.append(cols(
		box(h3('Mistakes'), plot({width: W2, height: 260, x: {label: 'board size'}, y: {label: 'mistakes', grid: true}, marks: [Plot.boxY(fin, {x: 'n', y: 'fails', fill: S[0]})]})),
		box(h3('Mistakes per needed merge'), plot({width: W2, height: 260, x: {label: 'board size'}, y: {label: 'mistakes per merge', grid: true}, marks: [Plot.boxY(fin, {x: 'n', y: g => g.fails / g.M, fill: S[0]})]}))));
}

// 5: abandonment and active time
{
	const s = sec('5', 'Abandoned games and active time', 'Abandoned: unfinished with no attempt in the last 2 days. Active time: the sum of the gaps between attempts of at most 2 minutes, which drops all pauses.');
	const gone = games.filter(g => !g.fin && now - g.lastT > 2 * 86400);
	const active = fin.filter(g => !g.mp).map(g => ({n: g.n, min: d3.sum(g.trace, r => r.dt != null && r.dt <= 120 ? r.dt : 0) / 60, wall: (g.finished - g.created) / 3600}));
	s.append(cols(
		box(h3(`Progress at abandonment (${gone.length} games)`), plot({width: W2, height: 200, x: {label: 'progress', domain: [0, 1]}, y: {label: 'board size'},
			marks: [Plot.tickX(gone, {x: g => g.succ / g.M, y: 'n', stroke: S[1], strokeWidth: 2, tip: true, title: g => `${g.seed} ${g.name}: ${g.succ}/${g.M} merges, ${g.fails} mistakes`})]})),
		box(h3('Active minutes of finished games'), plot({width: W2, height: 200, x: {label: 'board size'}, y: {label: 'minutes', grid: true}, marks: [Plot.boxY(active, {x: 'n', y: 'min', fill: S[0]})]}))));
}

// 9: category timing
{
	const s = sec('9', 'Categories finished early or late', 'Completion rank: order in which a board\'s categories are completed, 0 = first, 1 = last. Bulk: median progress of the category\'s merges. Categories in at least 3 finished games; dots are games, ticks the mean.');
	s.append(cols(box(h3('Completed earliest'), ranked(finOccs, {key: o => catLabel(o.c), value: o => o.rank, label: 'completion rank'})),
		box(h3('Completed latest'), ranked(finOccs, {key: o => catLabel(o.c), value: o => o.rank, label: 'completion rank', desc: true}))));
	s.append(cols(box(h3('Bulk of merges earliest'), ranked(finOccs, {key: o => catLabel(o.c), value: o => o.bulk, label: 'median merge progress'})),
		box(h3('Bulk of merges latest'), ranked(finOccs, {key: o => catLabel(o.c), value: o => o.bulk, label: 'median merge progress', desc: true}))));
}

// 10: parallel clusters
{
	const s = sec('10', 'Parallel clusters', 'Most clusters of 2+ items a category had at the same time before they were merged into one. 1 means it was built as a single growing cluster.');
	s.append(cols(
		box(h3('Share of category occurrences by most parallel clusters'), plot({width: W2, height: 240, fx: {label: 'board size'}, x: {label: null}, y: {label: 'share', tickFormat: '%', grid: true},
			marks: [Plot.barY(finOccs, Plot.groupX({y: 'proportion-facet'}, {x: 'maxPar', fx: o => o.g.n, fill: S[0], tip: true}))]})),
		box(h3('Categories most built in parallel (3+ games)'), ranked(finOccs, {key: o => catLabel(o.c), value: o => o.maxPar, label: 'most parallel clusters', desc: true, domain: null}))));
}

// 11 / 23: top confusions
{
	const s = sec('11 / 23', 'Top confusions', 'Mistakes between two categories compared to the number expected if mistakes paired categories at random, given how often each of the two was involved in that game\'s mistakes. Only counts games where both were on the board, so board size and category difficulty are factored out. Pairs with 3+ mistakes.');
	const obs = new Map(), exp = new Map(), gs = new Map(), key = (x, y) => x < y ? x + ',' + y : y + ',' + x;
	for (const g of games.filter(g => g.fails)) {
		const inv = new Map(), F = g.fails;
		for (const r of g.trace.filter(r => !r.s)) {
			for (const c of [r.ca, r.cb]) inv.set(c, (inv.get(c) || 0) + 1);
			const k = key(r.ca, r.cb);
			gs.set(k, (gs.get(k) || new Set()).add(g));
			obs.set(k, (obs.get(k) || 0) + 1);
		}
		const cs = [...inv.keys()];
		for (let i = 0; i < cs.length; i++) for (let j = i + 1; j < cs.length; j++) {
			const k = key(cs[i], cs[j]);
			exp.set(k, (exp.get(k) || 0) + 2 * inv.get(cs[i]) * inv.get(cs[j]) / (4 * F));
		}
	}
	const top = [...obs].filter(([, o]) => o >= 3).map(([k, o]) => { const [x, y] = k.split(',').map(Number); return {k: `${catLabel(x)} ↔ ${catLabel(y)}`, o, e: exp.get(k), lift: o / exp.get(k), games: gs.get(k).size}; })
		.sort((x, y) => y.lift - x.lift).slice(0, 30);
	s.append(plot({height: 30 + top.length * 16, marginLeft: 380, x: {label: 'mistakes / expected', grid: true}, y: {label: null, tickFormat: s => s.length > 60 ? s.slice(0, 59) + '…' : s},
		marks: [Plot.barX(top, {x: 'lift', y: 'k', fill: S[1], sort: {y: '-x'}, tip: true, title: d => `${d.k}\n${d.o} mistakes, ${d.e.toFixed(1)} expected, in ${d.games} games`}), Plot.ruleX([1], {stroke: MUTED})]}));
}

// 12: error rate by type
{
	const s = sec('12', 'Mistakes by category type', 'Mistakes involving a category of the type (each mistake counts for both its categories) per merge made in categories of that type.');
	const rows = d3.flatRollup(occs.filter(o => o.merges.length), v => ({fails: d3.sum(v, o => o.fails), merges: d3.sum(v, o => o.merges.length), count: v.length}), o => cats[o.c][2] || '(not in category table)', o => cats[o.c][0])
		.map(([type, lang, v]) => ({type, lang, ...v, y: v.fails / v.merges}));
	s.append(plot({height: 30 + rows.length * 14, marginLeft: 150, color: langColor, x: {label: 'mistakes per merge', grid: true}, fy: {label: null, domain: d3.groupSort(rows, v => -d3.sum(v, r => r.fails) / d3.sum(v, r => r.merges), r => r.type)}, y: {axis: null},
		marks: [Plot.barX(rows, {x: 'y', y: 'lang', fy: 'type', fill: 'lang', tip: true, title: d => `${d.type} (${d.lang}): ${d.y.toFixed(2)}\n${d.fails} mistakes, ${d.merges} merges, ${d.count} category occurrences`})]}));
}

// 14: streaks
{
	const s = sec('14', 'Mistake streaks', 'Runs of consecutive mistakes in single-player games.');
	const runs = [], after = {f: [0, 0], s: [0, 0], fq: [0, 0]};
	for (const g of solo) {
		let run = 0;
		g.trace.forEach((r, i) => {
			if (i) {
				const p = g.trace[i - 1], k = p.s ? 's' : 'f';
				after[k][0] += !r.s; after[k][1]++;
				if (!p.s && r.dt <= 10) { after.fq[0] += !r.s; after.fq[1]++; }
			}
			if (!r.s) run++; else { if (run) runs.push(Math.min(run, 15)); run = 0; }
		});
		if (run) runs.push(Math.min(run, 15));
	}
	s.append(cols(
		plot({width: W2, height: 240, x: {label: 'streak length (15 = 15+)'}, y: {label: 'streaks', type: 'log', grid: true},
			marks: [Plot.barY(runs, Plot.groupX({y: 'count'}, {x: d => d, fill: S[1], tip: true}))]}),
		table(['', 'chance of a mistake'], [['after a correct merge', pct(after.s[0] / after.s[1])], ['after a mistake', pct(after.f[0] / after.f[1])], ['after a mistake, within 10 s', pct(after.fq[0] / after.fq[1])]])));
}

// 15: players over time
{
	const s = sec('15', 'Players over time', 'Mistakes per needed merge of each finished single-player game, in the order played. Signed-in players with 3+ finished games; same y scale everywhere.');
	const per = d3.groups(fin.filter(g => !g.mp && g.by), g => g.by).filter(([, v]) => v.length >= 3).sort((x, y) => y[1].length - x[1].length);
	const max = d3.max(per, ([, v]) => d3.max(v, g => g.fails / g.M)), grid = document.createElement('div');
	grid.className = 'grid';
	for (const [by, v] of per) grid.append(box(h3(`${names[by] || by} (${v.length})`), plot({width: 220, height: 90, margin: 4, marginLeft: 28, marginBottom: 6, x: {axis: null}, y: {domain: [0, max], ticks: 3, grid: true, label: null},
		marks: [Plot.line(v, {x: (g, i) => i, y: g => g.fails / g.M, stroke: S[0], strokeWidth: 1.5}), Plot.dot(v, {x: (g, i) => i, y: g => g.fails / g.M, fill: g => S[sizes.indexOf(g.n)], r: 2.5, tip: true, title: g => `${new Date(g.created * 1000).toLocaleDateString()} ${g.n}×${g.n} ${g.lang}: ${g.fails} mistakes`})]})));
	s.append(grid);
}

// 16: merge kinds
{
	const s = sec('16', 'Kinds of merges over the game', 'What was combined, by cluster sizes before the attempt. "First" is the first click. Shares within each 5% of progress, all games.');
	const kinds = ['single + single', 'cluster first, then single', 'single first, then cluster', 'cluster + cluster'];
	const kind = r => r.sa == 1 && r.sb == 1 ? kinds[0] : r.sa > 1 && r.sb > 1 ? kinds[3] : r.sb > 1 ? kinds[1] : kinds[2];
	const rows = ok => { const c = d3.rollup(atts.filter(r => r.s == ok), v => v.length, r => bin(r.prog), kind); return d3.range(B).flatMap(b => kinds.map(k => ({x: mid(b), kind: k, count: c.get(b)?.get(k) || 0}))); };
	const color = {domain: kinds, range: S.slice(0, 4), legend: true};
	for (const [ok, title] of [[1, 'Correct merges'], [0, 'Mistakes']])
		s.append(h3(title), plot({height: 220, color, x: {label: 'progress'}, y: {label: 'share', tickFormat: '%'},
			marks: [Plot.areaY(rows(ok), {x: 'x', y: 'count', fill: 'kind', order: kinds, offset: 'normalize', curve: 'monotone-x', tip: true, title: d => `${d.kind} at ${pct(d.x)}: ${d.count}`})]}));
}

// 17: random baseline and guessing onset
{
	const s = sec('17', 'Skill vs. random pairs', 'Success rate of attempts compared to the chance that a random pair of clusters on the current board matches. The gap is how much players know; where the lines meet, they are guessing. Log scale.');
	const rows = [];
	for (const n of sizes) for (const [b, v] of d3.group(atts.filter(r => r.g.n == n), r => bin(r.prog)))
		rows.push({n, x: mid(b), y: d3.mean(v, r => r.s), series: 'players'}, {n, x: mid(b), y: d3.mean(v, r => r.p), series: 'random pairs'});
	s.append(plot({height: 260, fx: {label: 'board size'}, color: {domain: ['players', 'random pairs'], range: [S[0], MUTED], legend: true}, x: {label: 'progress', ticks: [.25, .5, .75], tickFormat: '%'}, y: {type: 'log', label: 'success rate', grid: true, tickFormat: '.0%'},
		marks: [Plot.line(rows, {x: 'x', y: 'y', stroke: 'series', fx: 'n', strokeWidth: 2, curve: 'monotone-x', tip: true, title: d => `${d.series} at ${pct(d.x)}: ${pct(d.y)}`})]}));
	const on = games.filter(g => g.onset != null);
	s.append(h3(`Guessing onset: first 10 attempts with at most twice the random success rate (${on.length} of ${games.length} games have one)`),
		plot({height: 200, x: {label: 'progress at onset', domain: [0, 1]}, y: {label: 'board size'},
			marks: [Plot.tickX(on, {x: 'onsetProg', y: 'n', stroke: S[1], strokeWidth: 2, tip: true, title: g => `${g.seed} ${g.name}: onset at ${pct(g.onsetProg)}${g.fin ? '' : ', unfinished'}`})]}));
}

// 18: probing runs
{
	const s = sec('18', 'Probing runs', 'Three or more mistakes in a row that share one cluster: either a cluster tried against many others, or a single item tried against many. Single-player games. Found: the next attempt merges the shared cluster.');
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
		table(['kind', 'runs', 'mean length', 'found'], kinds.map(k => { const v = runs.filter(r => r.kind == k); return [k, v.length, v.length ? d3.mean(v, r => r.len).toFixed(1) : '', v.length ? pct(d3.mean(v, r => r.found)) : '']; }))));
}

// 19: categories found by guessing
{
	const s = sec('19', 'Categories found by guessing', 'The first merge of a category came after the game\'s guessing onset (see 17), or right after 2+ mistakes involving one of the two items. Categories in 3+ games.');
	const rows = occs.filter(o => o.disc != null).map(o => {
		const t = o.g.trace, d = o.disc, r = t[d], prev = t.slice(Math.max(0, d - 2), d);
		return {o, guessed: (o.g.onset != null && d >= o.g.onset) || (prev.length == 2 && prev.every(p => !p.s && [p.a, p.b].some(x => x == r.a || x == r.b)))};
	});
	const top = d3.groups(rows, r => catLabel(r.o.c)).filter(([, v]) => v.length >= 3).map(([k, v]) => ({k, y: d3.mean(v, r => r.guessed), count: v.length}))
		.sort((x, y) => y.y - x.y || y.count - x.count).slice(0, 30);
	s.querySelector('.note').textContent += ` Overall ${pct(d3.mean(rows, r => r.guessed))} of category discoveries.`;
	s.append(plot({height: 30 + top.length * 16, marginLeft: 260, x: {label: 'share of games found by guessing', tickFormat: '%', domain: [0, 1], grid: true}, y: {label: null, tickFormat: short},
		marks: [Plot.barX(top, {x: 'y', y: 'k', fill: S[1], sort: {y: '-x'}, tip: true, title: d => `${d.k}: ${pct(d.y)} of ${d.count} games`})]}));
}

// 20 / 21: items
{
	const s = sec('20 / 21', 'Giveaway and obscure items', 'Join rank: when an item stopped being single, relative to its category on that board: 0 = one of the two that started it, 1 = joined last. Finished games, items in 2+ games.');
	s.append(cols(box(h3('Giveaways: join earliest'), ranked(itemOccs, {key: o => o.key, value: o => o.rank, label: 'join rank', min: 2})),
		box(h3('Obscure: join latest'), ranked(itemOccs, {key: o => o.key, value: o => o.rank, label: 'join rank', min: 2, desc: true}))));
	s.append(cols(box(h3('Most mistakes while still single, per game'), ranked(itemOccs, {key: o => o.key, value: o => o.fs, label: 'mistakes while single', min: 2, desc: true, domain: null})), box()));
	const flagged = new Set(obscure.map(([c, item]) => c + '|' + item)), fb = new Map();
	for (const [c, item, value, n] of feedback) { const k = c + '|' + item; fb.set(k, (fb.get(k) || []).concat([[value, n]])); }
	const vote = k => fb.has(k) ? d3.greatest(fb.get(k), v => v[1])[0] : null;
	const groups = itemOccs.flatMap(o => {
		const k = o.c + '|' + o.item, v = vote(k);
		return [{group: flagged.has(k) ? 'flagged obscure' : 'not flagged', rank: o.rank, fs: o.fs}].concat(v ? [{group: 'feedback: ' + v, rank: o.rank, fs: o.fs}] : []);
	});
	const order = ['not flagged', 'flagged obscure', 'feedback: normal', 'feedback: obscure', 'feedback: none'], label = g => `${g} (${groups.filter(r => r.group == g).length})`;
	s.append(h3('Join rank by curation flag and by players\' feedback (most votes per item)'), cols(
		plot({width: W2, height: 200, marginLeft: 150, x: {label: 'join rank', grid: true}, y: {label: null, domain: order, tickFormat: label}, marks: [Plot.boxX(groups, {x: 'rank', y: 'group', fill: S[0]})]}),
		plot({width: W2, height: 200, marginLeft: 150, x: {label: 'mistakes while single', grid: true}, y: {label: null, domain: order, tickFormat: label}, marks: [Plot.boxX(groups, {x: 'fs', y: 'group', fill: S[0]})]})));
}

// 22: items tried together
{
	const s = sec('22', 'Items tried together', 'Two single items of different categories tried as a pair, in 2+ different games.');
	const pairs = new Map();
	for (const r of atts.filter(r => !r.s && r.sa == 1 && r.sb == 1)) {
		const w = [`${r.g.items[r.a][0]} (${catLabel(r.ca)})`, `${r.g.items[r.b][0]} (${catLabel(r.cb)})`].sort().join(' + ');
		pairs.set(w, (pairs.get(w) || new Set()).add(r.g));
	}
	const top = [...pairs].filter(([, v]) => v.size >= 2).map(([k, v]) => ({k, y: v.size})).sort((x, y) => y.y - x.y).slice(0, 30);
	s.append(top.length ? plot({height: 30 + top.length * 16, marginLeft: 420, x: {label: 'games', grid: true}, y: {label: null, tickFormat: s => s.length > 70 ? s.slice(0, 69) + '…' : s},
		marks: [Plot.barX(top, {x: 'y', y: 'k', fill: S[1], sort: {y: '-x'}, tip: true})]}) : 'No pair yet.');
}

// 24: category difficulty profile
{
	const s = sec('24', 'Category difficulty profile', 'Pattern point: progress at which a third of the category\'s merges were done. Completion span: progress from there to its last merge. Colour: mistakes involving it per merge. Means over finished games, categories in 3+ games.');
	const rows = d3.groups(finOccs, o => catLabel(o.c)).filter(([, v]) => v.length >= 3)
		.map(([k, v]) => ({k, x: d3.mean(v, o => o.pattern), y: d3.mean(v, o => o.complete - o.pattern), m: d3.mean(v, o => o.perMerge), count: v.length}));
	s.append(plot({height: 420, color: {type: 'linear', range: ['#b7d3f6', '#0d366b'], legend: true, label: 'mistakes per merge'}, x: {label: 'pattern point (progress)', grid: true}, y: {label: 'completion span', grid: true},
		marks: [Plot.dot(rows, {x: 'x', y: 'y', fill: 'm', r: 5, stroke: col('surface'), tip: true, title: d => `${d.k}\npattern at ${pct(d.x)}, span ${pct(d.y)}\n${d.m.toFixed(2)} mistakes per merge, ${d.count} games`})]}));
}

// 25: same board, several players
{
	const s = sec('25', 'Same board, several players', 'Single-player boards played by 2+ players: mistakes made before each correct merge.');
	const grid = document.createElement('div');
	grid.className = 'cols';
	for (const [seed, v] of d3.groups(solo.filter(g => g.att.length), g => g.seed).filter(([, v]) => v.length >= 2)) {
		const pts = v.flatMap(g => g.cum.map((y, x) => ({x, y, name: g.name + (g.fin ? '' : ' (unfinished)')})));
		grid.append(box(h3(`${seed} · ${v[0].n}×${v[0].n} ${v[0].lang}`), plot({width: W2, height: 180, color: {legend: true, range: S}, x: {label: 'correct merges'}, y: {label: 'mistakes', grid: true},
			marks: [Plot.line(pts, {x: 'x', y: 'y', stroke: 'name', strokeWidth: 1.5, tip: true})]})));
	}
	s.append(grid);
}

// 26: multiplayer
{
	const s = sec('26', 'Multiplayer shares', 'Correct merges and mistakes per player in each multiplayer game with 2+ players; colour is the player\'s rank by merges within the game.');
	const rows = games.filter(g => g.mp && new Set(g.att.map(r => r[4])).size > 1).flatMap(g => {
		const label = `${g.seed} · ${g.n}×${g.n}`, per = d3.rollups(g.trace, v => ({merges: d3.sum(v, r => r.s), mistakes: d3.sum(v, r => !r.s)}), r => r.u).sort((x, y) => y[1].merges - x[1].merges);
		return per.flatMap(([u, v], i) => [['merges', v.merges], ['mistakes', v.mistakes]].map(([measure, x]) => ({label, measure, x, rank: String(i + 1), name: names[u] || 'guest'})));
	});
	const ranks = [...new Set(rows.map(r => r.rank))].sort((x, y) => x - y);
	s.append(plot({height: 40 + new Set(rows.map(r => r.label)).size * 22, marginLeft: 130, fx: {label: null}, color: {domain: ranks, range: S, legend: true, label: 'rank'}, x: {grid: true, label: null}, y: {label: null},
		marks: [Plot.barX(rows, {x: 'x', y: 'label', fx: 'measure', fill: 'rank', order: ranks, tip: true, title: d => `${d.name}: ${d.x} ${d.measure}`})]}));
}

// 7: single game
{
	const s = sec('7', 'Single game', 'One game in detail: success rate over the last 10 attempts against the random-pair rate, and each category\'s merges and mistakes in attempt order.');
	const select = document.createElement('select'), out = document.createElement('div'), list = [...games].filter(g => g.att.length).sort((x, y) => y.created - x.created);
	select.innerHTML = list.map((g, i) => `<option value="${i}">${new Date(g.created * 1000).toLocaleDateString()} · ${g.seed} · ${g.n}×${g.n} ${g.lang} · ${g.name} · ${g.fails} mistakes${g.fin ? '' : ' · unfinished'}</option>`).join('');
	select.value = list.findIndex(g => g.fin && g.n >= 10);
	const show = () => {
		const g = list[select.value], t = g.trace, roll = t.slice(9).map((r, i) => ({i: i + 9, players: d3.mean(t.slice(i, i + 10), r => r.s), random: d3.mean(t.slice(i, i + 10), r => r.p)}));
		const word = i => g.items[i][0], order = [...g.oc].sort((x, y) => (x.done ?? 1e9) - (y.done ?? 1e9)).map(o => catLabel(o.c));
		const ev = t.flatMap(r => (r.s ? [r.ca] : [r.ca, r.cb]).map(c => ({i: r.i, c: catLabel(c), kind: r.s ? 'merge' : 'mistake', text: `#${r.i} ${word(r.b)} (${r.sb}) → ${word(r.a)} (${r.sa})${r.s ? '' : ' ✗'}`})));
		out.replaceChildren(
			plot({height: 220, color: {domain: ['players', 'random'], range: [S[0], MUTED], legend: true}, x: {label: 'attempt'}, y: {label: 'success rate', grid: true, domain: [0, 1]},
				marks: [Plot.line(roll, {x: 'i', y: 'players', stroke: S[0], strokeWidth: 2}), Plot.line(roll, {x: 'i', y: 'random', stroke: MUTED, strokeWidth: 2}),
					g.onset != null ? Plot.ruleX([g.onset], {stroke: S[1]}) : null, Plot.tip(roll, Plot.pointerX({x: 'i', y: 'players', title: d => `#${d.i}: ${pct(d.players)} vs. ${pct(d.random)} random`}))]}),
			plot({height: 40 + order.length * 15, marginLeft: 200, color: {domain: ['merge', 'mistake'], range: S.slice(0, 2), legend: true}, x: {label: 'attempt', grid: true}, y: {domain: order, label: null, tickFormat: short},
				marks: [Plot.dot(ev, {x: 'i', y: 'c', fill: 'kind', r: 3, tip: true, title: 'text'})]}));
	};
	select.onchange = show;
	s.append(select, out);
	show();
}
</script>
</body>
</html>
