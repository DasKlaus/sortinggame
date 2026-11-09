<?php
// Curation of memberships and kept-apart pairs, for user 1 only. ?lang= loads one language once: its sorting games for replay.js, the open feedback, the categories,
// apart pairs and strongly overlapping pairs. A category's members, an item's categories and the item pool are fetched when needed (get=), each once;
// decisions are posted (action=) and saved at once. Curation logs its own decisions in feedback as user 1 with the language as seed (see schema.sql).
require_once "config.php";
require_once "../identity.php";
if ($_SESSION['user_id'] != 1)
{
	http_response_code(403);
	exit;
}
$lang = $_GET['lang'] ?? '';
$q = fn($sql, $params = []) => $mysql->execute_query($sql, $params);
$langSeeds = "select seed from game where language = ? and mode = 'sorting'";

if ($_SERVER['REQUEST_METHOD'] == 'POST')
{
	$p = $_POST;
	$name = fn($id) => $q("select name from category where id = ?", [$id])->fetch_row()[0];
	$inPool = fn($item) => (bool)$q("select 1 from membership m join category c on c.id = m.category_id where c.language = ? and m.item = ? limit 1", [$lang, $item])->fetch_row();
	$log = fn($kind, $item, $cat, $other, $value, $done = 1) => $q("insert into feedback (seed, user_id, display_name, kind, item, category, other, value, created_at, done) values (?, 1, '', ?, ?, ?, ?, ?, now(), ?)
		on duplicate key update value = values(value), created_at = now(), done = values(done)", [$lang, $kind, $item, $cat, $other, $value, $done]);
	$resolve = fn($item, $cat) => $q("update feedback set done = 1 where kind in ('membership', 'spelling') and item = ? and category = ? and seed in ($langSeeds)", [$item, $cat, $lang]);
	$set = fn($cat, $item, $level) => $q("insert into membership (category_id, item, level) values (?, ?, ?) on duplicate key update level = values(level)", [$cat, $item, $level]);
	// A rename onto a name the category already has keeps that one
	$move = function($cat, $from, $to) use ($q) {
		$q("update ignore membership set item = ? where category_id = ? and item = ?", [$to, $cat, $from]);
		$q("delete from membership where category_id = ? and item = ?", [$cat, $from]);
	};
	$unpend = fn($cat, $item, $other) => $q("delete from feedback where seed = ? and user_id = 1 and kind = 'pending' and item = ? and category = ? and other = ?", [$lang, $item, $cat, $other]);
	switch ($p['action'])
	{
		// A name not yet in the item pool waits as pending, for adding and renaming alike; answers "pending" then
		case 'level':
			$c = $name($p['cat']);
			$resolve($p['item'], $c);
			if (!$inPool($p['item']))
			{
				$log('pending', $p['item'], $c, '', $p['level'], 0);
				echo 'pending';
				break;
			}
			$set($p['cat'], $p['item'], $p['level']);
			$log('membership', $p['item'], $c, '', $p['level']);
			break;
		case 'remove':
			$c = $name($p['cat']);
			$resolve($p['item'], $c);
			$q("delete from membership where category_id = ? and item = ?", [$p['cat'], $p['item']]);
			$log('membership', $p['item'], $c, '', 'none');
			break;
		case 'rename':
			$c = $name($p['cat']);
			$resolve($p['item'], $c);
			if (!$inPool($p['to']))
			{
				$log('pending', $p['to'], $c, $p['item'], $p['level'], 0);
				echo 'pending';
				break;
			}
			$move($p['cat'], $p['item'], $p['to']);
			break;
		case 'approve':
			$c = $name($p['cat']);
			if ($p['other'] !== '') $move($p['cat'], $p['other'], $p['item']);
			$set($p['cat'], $p['item'], $p['level']);
			$log('membership', $p['item'], $c, '', $p['level']);
			$unpend($c, $p['item'], $p['other']);
			break;
		case 'drop':
			$unpend($name($p['cat']), $p['item'], $p['other']);
			break;
		case 'apart':
			[$a, $b] = [(int)$p['a'], (int)$p['b']];
			$pair = [$name($a), $name($b)];
			sort($pair, SORT_STRING);
			if ($p['keep']) $q("insert ignore into apart (a, b) values (?, ?)", [min($a, $b), max($a, $b)]);
			else $log('apart', '', $pair[0], $pair[1], '0');
			$q("update feedback set done = 1 where kind = 'apart' and category = ? and other = ? and seed in ($langSeeds)", [...$pair, $lang]);
			break;
		case 'unapart':
			$q("delete from apart where a = ? and b = ?", [min($p['a'], $p['b']), max($p['a'], $p['b'])]);
			break;
		case 'playable':
			$q("update category set playable = ? where id = ?", [$p['playable'], $p['cat']]);
			break;
		case 'done':
			$q("update feedback set done = 1 where seed = ? and user_id = ? and kind = 'text'", [$p['seed'], $p['user']]);
			break;
	}
	exit;
}

if (isset($_GET['get']))
{
	header('Content-Type: application/json');
	switch ($_GET['get'])
	{
		// Per category id its items with their levels
		case 'members':
			$ids = array_map('intval', explode(',', $_GET['ids']));
			$out = array_fill_keys($ids, []);
			foreach ($q("select category_id, item, level from membership where category_id in (" . implode(',', $ids) . ")") as $r) $out[$r['category_id']][] = [$r['item'], (int)$r['level']];
			echo json_encode($out, JSON_UNESCAPED_UNICODE);
			break;
		// Every spelling of the item ignoring case and accents, with its categories
		case 'item':
			echo json_encode($q("select m.item, c.id, c.name, m.level from membership m join category c on c.id = m.category_id where c.language = ? and m.item collate utf8mb4_unicode_ci = ? order by m.item, c.name", [$lang, $_GET['item']])->fetch_all(), JSON_UNESCAPED_UNICODE);
			break;
		// Items starting with the prefix ignoring case and accents, most categories first, and pending names
		case 'pool':
			$like = addcslashes($_GET['prefix'], '%_\\') . '%';
			echo json_encode(['pool' => $q("select m.item, count(*) from membership m join category c on c.id = m.category_id where c.language = ? and m.item collate utf8mb4_unicode_ci like ? group by m.item order by count(*) desc, m.item limit 20", [$lang, $like])->fetch_all(),
				'pending' => array_column($q("select distinct item from feedback where seed = ? and user_id = 1 and kind = 'pending' and item collate utf8mb4_unicode_ci like ?", [$lang, $like])->fetch_all(), 0)], JSON_UNESCAPED_UNICODE);
			break;
	}
	exit;
}

if ($lang)
{
	// Categories as [id, name, type, topic, playable, size]; names on old boards that no longer exist get a negative id
	$cats = $ids = [];
	foreach ($q("select c.id, c.name, c.type, c.topic, c.playable, count(m.item) from category c left join membership m on m.category_id = c.id where c.language = ? group by c.id", [$lang])->fetch_all() as $r)
	{
		$cats[] = $r = [(int)$r[0], $r[1], $r[2], $r[3], (int)$r[4], (int)$r[5]];
		if (!isset($ids[$r[1]]) or $r[4] > 0) $ids[$r[1]] = $r[0];
	}
	$games = [];
	foreach ($q("select seed, created_by, size, levels, items, multiplayer, unix_timestamp(created_at) c, unix_timestamp(finished_at) f from game where language = ? and mode = 'sorting' order by created_at", [$lang]) as $g)
	{
		$items = [];
		foreach (json_decode($g['items']) as [$word, $c])
		{
			if (!isset($ids[$c])) $cats[] = [$ids[$c] = -count($cats) - 1, $c, '', '', -1, 0];
			$items[] = [(string)$word, $ids[$c]];
		}
		$games["$g[seed]|$g[created_by]"] = ['seed' => $g['seed'], 'n' => (int)$g['size'], 'levels' => json_decode($g['levels'] ?? 'null'), 'mp' => (bool)$g['multiplayer'],
			'created' => (int)$g['c'], 'finished' => $g['f'] === null ? null : (int)$g['f'], 'items' => $items, 'att' => []];
	}
	foreach ($q("select a.seed, g.created_by, a.user_id, a.a, a.b, a.success, unix_timestamp(a.created_at) t from attempt a join game g on g.seed = a.seed and (g.multiplayer or g.created_by = a.user_id) where g.language = ? and g.mode = 'sorting' order by a.id", [$lang]) as $r)
		$games["$r[seed]|$r[created_by]"]['att'][] = [(int)$r['a'], (int)$r['b'], (int)$r['success'], (int)$r['t'], (int)$r['user_id']];
	$data = ['lang' => $lang, 'cats' => $cats, 'games' => array_values($games),
		'feedback' => $q("select seed, user_id, display_name, kind, item, category, other, value, unix_timestamp(created_at) from feedback where (seed = ? and user_id = 1) or (not done and seed in ($langSeeds))", [$lang, $lang])->fetch_all(),
		'apart' => $q("select a, b from apart join category on id = a where language = ?", [$lang])->fetch_all(),
		// Playable pairs sharing 3+ items and at least 30% of the smaller one
		'overlap' => $q("select t.a, t.b, t.shared from (select m1.category_id a, m2.category_id b, count(*) shared from membership m1 join membership m2 on m2.item = m1.item and m2.category_id > m1.category_id
			join category c1 on c1.id = m1.category_id join category c2 on c2.id = m2.category_id where c1.language = ? and c2.language = ? and c1.playable > 0 and c2.playable > 0 group by a, b having shared >= 3) t
			join (select category_id, count(*) n from membership group by category_id) x on x.category_id = t.a join (select category_id, count(*) n from membership group by category_id) y on y.category_id = t.b
			where t.shared >= .3 * least(x.n, y.n)", [$lang, $lang])->fetch_all()];
}
ob_start('ob_gzhandler');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<title>Sorting Game Curation</title>
<link rel="icon" href="favicon.svg">
<style>
:root { color-scheme: light; --bg: #f9f9f7; --surface: #fcfcfb; --ink: #0b0b0b; --ink2: #52514e; --muted: #898781; --border: rgba(11,11,11,.1); --hover: rgba(11,11,11,.05);
	--accent: #2a78d6; --good: #1baf7a; --warn: #c98500; --bad: #e34948; }
@media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #0d0d0d; --surface: #1a1a19; --ink: #f0efec; --ink2: #c3c2b7; --muted: #898781; --border: rgba(255,255,255,.1); --hover: rgba(255,255,255,.06);
	--accent: #3987e5; --good: #199e70; --warn: #c98500; --bad: #e66767; } }
body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1400px; margin: 0 auto; padding: 16px 24px 80px; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
button { font: inherit; color: inherit; background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 1px 8px; cursor: pointer; }
button:hover { background: var(--hover); }
input[type=text], select, textarea { font: inherit; color: inherit; background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 3px 6px; }
header { display: flex; align-items: center; gap: 20px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
header h1 { font-size: 18px; margin: 0; }
header .langs a.on { color: var(--ink); font-weight: 600; }
header nav { display: flex; gap: 4px; }
header nav a { padding: 4px 10px; border-radius: 4px; color: var(--ink2); }
header nav a.on { background: var(--hover); color: var(--ink); font-weight: 600; }
.find { position: relative; margin-left: auto; }
.find input { width: 320px; }
.drop { position: absolute; z-index: 5; background: var(--surface); border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,.15); min-width: 320px; max-height: 420px; overflow: auto; }
.drop div { padding: 3px 8px; cursor: pointer; white-space: nowrap; }
.drop div:hover { background: var(--hover); }
.drop small, .muted { color: var(--muted); }
table { border-collapse: collapse; font-variant-numeric: tabular-nums; width: 100%; }
th { text-align: left; font-weight: 600; color: var(--ink2); padding: 4px 8px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 3px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; white-space: nowrap; }
td.notes { white-space: normal; }
tr.group td { font-weight: 600; color: var(--ink2); padding-top: 14px; border-bottom: 0; }
tr.done td { opacity: .45; }
tr.done td.lv, tr.done td.x { opacity: 1; }
.num { text-align: right; }
.bad { color: var(--bad); } .warn { color: var(--warn); } .good { color: var(--good); }
.seg { display: inline-flex; }
.seg label input { display: none; }
.seg label span { display: inline-block; padding: 1px 8px; border: 1px solid var(--border); margin-left: -1px; cursor: pointer; background: var(--surface); }
.seg label:first-child span { border-radius: 4px 0 0 4px; margin-left: 0; }
.seg label:last-child span { border-radius: 0 4px 4px 0; }
.seg label:only-child span { border-radius: 4px; }
.seg label span:hover { background: var(--hover); }
.seg input:checked + span { background: var(--ink); color: var(--bg); border-color: var(--ink); }
.name { border: 0; background: none; padding: 0; text-align: left; }
.name:hover { background: none; text-decoration: underline; }
.bar { display: inline-block; height: 8px; background: var(--accent); border-radius: 2px; vertical-align: middle; }
.chip { font-size: 12px; margin: 0 4px 0 0; }
.cat { display: grid; grid-template-columns: 1fr 300px; gap: 24px; margin-top: 12px; }
.cat h2 { margin: 0; font-size: 20px; }
.cat .head { margin-bottom: 8px; }
.cat .head p { margin: 4px 0; color: var(--ink2); }
aside { position: sticky; top: 12px; align-self: start; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; max-height: calc(100vh - 40px); overflow: auto; }
aside h3 { font-size: 13px; margin: 8px 0 4px; }
aside div { margin: 1px 0; }
.addrow { margin-top: 12px; display: flex; gap: 8px; align-items: center; position: relative; }
.addrow input { width: 260px; }
.addapart { position: relative; margin-top: 8px; }
.addapart input { width: 100%; box-sizing: border-box; }
.pair { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin: 8px 0; }
.pair.done { opacity: .5; padding: 4px 12px; }
.pair .ph { display: flex; gap: 12px; align-items: baseline; }
.pair .ph .stats { color: var(--ink2); }
.pair .ph .act { margin-left: auto; display: flex; gap: 6px; }
.cols { display: grid; gap: 12px; margin-top: 4px; }
.lst { margin: 3px 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; cursor: pointer; }
.lst.all { display: block; }
.lst b { color: var(--ink2); font-weight: 600; margin-right: 6px; }
.text { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin: 8px 0; display: flex; gap: 12px; }
.text p { margin: 2px 0 0; white-space: pre-wrap; flex: 1; }
.text.done { opacity: .45; }
.filters { margin: 12px 0; display: flex; gap: 12px; align-items: center; }
textarea { width: 100%; font-family: ui-monospace, Consolas, monospace; }
</style>
</head>
<body>
<main>
<header>
	<h1>Curation</h1>
	<span class="langs"><?php foreach (['de', 'en'] as $l) echo "<a href=\"?lang=$l\"" . ($l == $lang ? ' class="on"' : '') . ">$l</a> "; ?></span>
	<?php if ($lang): ?>
	<nav></nav>
	<span class="find"><input type="text" id="find" placeholder="Find a category" autocomplete="off"></span>
	<?php endif ?>
</header>
<div id="view"><?php if (!$lang) echo '<p>Pick a language; only that one is loaded.</p>'; ?></div>
</main>
<?php if ($lang): ?>
<script src="replay.js"></script>
<script>
const D = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
const lang = D.lang, view = document.getElementById('view');
const esc = s => String(s).replace(/[&<>"']/g, ch => `&#${ch.charCodeAt(0)};`);
const sum = a => a.reduce((x, y) => x + y, 0);
const pairKey = (x, y) => x < y ? x + ',' + y : y + ',' + x;
// m.get(a).get(b), made on first use
const entry = (m, a, b, make) => { let x = m.get(a); if (!x) m.set(a, x = new Map()); if (!x.has(b)) x.set(b, make()); return x.get(b); };
const post = (action, params) => fetch(`?lang=${lang}`, {method: 'POST', body: new URLSearchParams({action, ...params})}).then(r => { if (!r.ok) throw r.status; return r.text(); });
const fetched = {};
const get = query => fetched[query] ??= fetch(`?lang=${lang}&get=${query}`).then(r => r.json());
// Members per category id as item → level, fetched once and kept up to date by the decisions
const memberMaps = new Map();
async function members(ids) {
	const need = ids.filter(id => !memberMaps.has(id));
	if (need.length) {
		const r = await fetch(`?lang=${lang}&get=members&ids=${need.join(',')}`).then(r => r.json());
		for (const id of need) memberMaps.set(id, new Map(r[id]));
	}
	return memberMaps;
}

const cats = new Map(D.cats.map(([id, name, type, topic, playable, size]) => [id, {id, name, type, topic, playable, size}]));
const byName = new Map();
for (const c of cats.values()) if (!byName.has(c.name) || c.playable > 0) byName.set(c.name, c);
const catLink = id => `<a href="#c${id}">${esc(cats.get(id).name)}</a>`;
const apart = new Set(D.apart.map(([a, b]) => pairKey(+a, +b)));
const levelName = {1: 'easy', 2: 'medium', 3: 'hard', 4: 'blocking'};

// Per category and item on boards: appearances, failed tries while on its own before the guessing onset (summed per board size),
// and positions found (0 first merge of its category, 1 last). Near misses: an item on its own tried with a cluster of 2+ of another category before the onset;
// each player's tries in a game count once per category and share a weight of 1 between all categories the item was tried with.
const games = D.games, play = new Map(), near = new Map(), gameBySeed = new Map(), wordGames = new Map();
replay(games);
for (const g of games) {
	if (!gameBySeed.has(g.seed)) gameBySeed.set(g.seed, g);
	g.cats = new Set(g.items.map(x => x[1]));
	const n = g.n, onset = g.onset ?? Infinity, fails = g.items.map(() => 0), merges = new Map(), tried = new Map();
	const stat = i => entry(play, g.items[i][1], g.items[i][0], () => ({boards: 0, fails: 0, pos: []}));
	for (const r of g.trace) {
		if (r.s) {
			const k = merges.get(r.ca) || 0;
			merges.set(r.ca, k + 1);
			for (const [x, size] of [[r.a, r.sa], [r.b, r.sb]]) if (size == 1) stat(x).pos.push(k / (n - 2));
		} else if (r.i < onset) for (const [x, size, other, otherSize] of [[r.a, r.sa, r.cb, r.sb], [r.b, r.sb, r.ca, r.sa]]) if (size == 1) {
			fails[x]++;
			const t = entry(tried, r.u, x, () => new Map());
			t.set(other, Math.max(t.get(other) || 0, otherSize));
		}
	}
	g.items.forEach(([w], i) => {
		const s = stat(i);
		s.boards++;
		s.fails += fails[i] / n;
		if (!wordGames.has(w)) wordGames.set(w, []);
		wordGames.get(w).push(g);
	});
	for (const [u, xs] of tried) for (const [x, t] of xs) for (const [c, size] of t) if (size >= 2) {
		const e = entry(near, c, g.items[x][0], () => ({ev: 0, players: new Set()}));
		e.ev += 1 / t.size;
		e.players.add(u);
	}
}

// Open feedback per category and item; curation's own rows: decided memberships and pairs, pending names
const fb = new Map(), votes = new Map(), texts = [], decided = new Set(), fineDecided = new Set(), pending = [];
for (const [seed, user, name, kind, item, category, other, value, t] of D.feedback) {
	if (seed == lang) {
		const c = byName.get(category);
		if (kind == 'membership' && c) decided.add(c.id + '\n' + item);
		else if (kind == 'apart' && c && byName.get(other)) fineDecided.add(pairKey(c.id, byName.get(other).id));
		else if (kind == 'pending') pending.push({item, category, other, level: +value});
		continue;
	}
	if (kind == 'text') { texts.push({seed, user, name, value, t: +t}); continue; }
	const c = byName.get(category);
	if (!c) continue;
	if (kind == 'apart') {
		const d = byName.get(other);
		if (!d) continue;
		const k = pairKey(c.id, d.id);
		if (!votes.has(k)) votes.set(k, {keep: 0, fine: 0});
		votes.get(k)[value == '1' ? 'keep' : 'fine']++;
		continue;
	}
	const e = entry(fb, c.id, item, () => ({normal: 0, obscure: 0, none: 0, spell: new Map(), member: !!gameBySeed.get(seed)?.items.some(([w, d]) => w == item && d == c.id)}));
	if (kind == 'spelling') e.spell.set(value, (e.spell.get(value) || 0) + 1);
	else e[value]++;
}

// Candidates: non-members with a vote that they count, or near misses with weight 2+ from 2+ players that curation hasn't decided yet.
// Without the members (the queue), membership is as on the board the feedback came from.
function candidates(c, M) {
	const out = new Set();
	for (const [w, e] of fb.get(c.id) || []) if (!(M ? M.has(w) : e.member) && e.normal + e.obscure > 0) out.add(w);
	for (const [w, e] of near.get(c.id) || []) if (e.ev >= 2 && e.players.size >= 2 && !decided.has(c.id + '\n' + w) && !M?.has(w)) out.add(w);
	return [...out];
}
function summary(c) {
	const s = {wrong: 0, obscure: 0, fine: 0, spelling: 0, cand: candidates(c).length};
	for (const e of fb.get(c.id)?.values() || []) {
		s.spelling += sum([...e.spell.values()]);
		if (e.member) { s.wrong += e.none; s.obscure += e.obscure; s.fine += e.normal; }
	}
	s.prio = 3 * s.wrong + s.obscure + 2 * s.spelling + s.cand + .5 * s.fine;
	return s;
}
// Position found (pulled toward 0.5 with a weight of 2 boards) + failed tries per board size + half the share of obscure votes
function estimate(c, w) {
	const s = play.get(c.id)?.get(w), e = fb.get(c.id)?.get(w), n = e ? e.normal + e.obscure + e.none : 0;
	return s ? (sum(s.pos) + 1) / (s.pos.length + 2) + s.fails / s.boards + (n ? .5 * e.obscure / n : 0) : null;
}

// Pairs: open apart votes, confusions above expectation on 2+ boards, and strong overlaps; those with keep-apart votes first, then those with play data
const conf = confusions(games), pairs = new Map();
const pairOf = k => pairs.get(k) || pairs.set(k, {k, ab: k.split(',').map(Number), keep: 0, fine: 0, obs: 0, exp: 0, boards: 0, shared: 0}).get(k);
for (const [k, v] of votes) Object.assign(pairOf(k), v);
for (const [k, p] of conf) if (p.boards.size >= 2 && p.obs > p.exp) pairOf(k);
for (const [a, b, shared] of D.overlap) pairOf(pairKey(+a, +b)).shared = +shared;
const pairQueue = [...pairs.values()].filter(p => p.ab.every(id => cats.get(id)?.playable > 0) && !apart.has(p.k) && (p.keep + p.fine || !fineDecided.has(p.k))).map(p => {
	const cf = conf.get(p.k);
	if (cf) Object.assign(p, {obs: cf.obs, exp: cf.exp, boards: cf.boards.size});
	p.share = p.shared / Math.min(...p.ab.map(id => cats.get(id).size));
	p.play = p.keep + p.fine + p.boards > 0;
	p.score = p.play ? p.keep - p.fine + p.obs - p.exp : p.share;
	return p;
}).sort((x, y) => (y.keep > 0) - (x.keep > 0) || y.play - x.play || y.score - x.score);

// Item pool suggestions under an input: fills it on click. Whether the typed name is in the pool goes to the status element if given, else atop the suggestions
const drop = document.createElement('div');
drop.className = 'drop';
drop.hidden = true;
drop.onmousedown = e => e.preventDefault();
let dropTimer;
function suggest(input, status) {
	clearTimeout(dropTimer);
	const v = input.value.trim();
	if (status) status.textContent = '';
	if (!v) return drop.hidden = true;
	dropTimer = setTimeout(async () => {
		const {pool, pending: pend} = await get('pool&prefix=' + encodeURIComponent(v));
		// Typed on or added meanwhile
		if (input.value.trim() != v || document.activeElement != input) return;
		const exact = pool.find(([w]) => w == v);
		const note = exact ? `in the pool, ${exact[1]} categories` : pend.includes(v) ? 'pending already' : 'not in the pool: stays pending until checked';
		const list = pool.map(([w, n]) => `<div data-v="${esc(w)}">${esc(w)} <small>${n}</small></div>`).join('') + pend.map(w => `<div data-v="${esc(w)}">${esc(w)} <small>pending</small></div>`).join('');
		if (status) status.textContent = note;
		drop.innerHTML = (status ? '' : `<small>${note}</small>`) + list;
		input.parentNode.append(drop);
		drop.style.top = input.offsetTop + input.offsetHeight + 'px';
		drop.style.left = input.offsetLeft + 'px';
		drop.hidden = status && !list;
		drop.onclick = e => { const d = e.target.closest('[data-v]'); if (d) { input.value = d.dataset.v; drop.hidden = true; input.focus(); } };
	}, 150);
}
document.addEventListener('focusout', () => drop.hidden = true);

// Category search over all categories of the language, calling pick with the chosen id
function catSearch(input, pick) {
	const d = document.createElement('div');
	d.className = 'drop';
	d.hidden = true;
	d.onmousedown = e => e.preventDefault();
	input.after(d);
	input.oninput = () => {
		const v = input.value.trim().toLowerCase();
		const hits = v ? [...cats.values()].filter(c => c.id > 0 && c.name.toLowerCase().includes(v)).slice(0, 20) : [];
		d.innerHTML = hits.map(c => `<div data-id="${c.id}">${esc(c.name)} <small>${esc(c.type)} · ${esc(c.topic)}${c.playable < 0 ? ' · off' : ''}</small></div>`).join('');
		d.hidden = !hits.length;
	};
	d.onclick = e => { const x = e.target.closest('[data-id]'); if (x) { pick(+x.dataset.id); input.value = ''; d.hidden = true; } };
	input.onblur = () => d.hidden = true;
}
catSearch(document.getElementById('find'), id => location.hash = 'c' + id);

// Views: the tabs keep their content while open, a category view is built once per category
const panes = {}, tabs = ['categories', 'pairs', 'text', 'pending'];
const nav = document.querySelector('header nav');
function navigation(on) {
	const n = {categories: queued.size, pairs: pairQueue.filter(p => !p.decided).length, text: texts.filter(t => !t.done).length, pending: pending.length};
	nav.innerHTML = tabs.map(t => `<a href="#${t}"${t == on ? ' class="on"' : ''}>${t[0].toUpperCase() + t.slice(1)} (${n[t]})</a>`).join('');
}
function pane(key, build) {
	for (const p of Object.values(panes)) p.hidden = true;
	if (!panes[key]) {
		panes[key] = document.createElement('div');
		view.append(panes[key]);
		build(panes[key]);
	}
	panes[key].hidden = false;
	return panes[key];
}
let lastCat = null;
function route() {
	const h = location.hash.slice(1) || 'categories';
	// Leaving a category updates its queue entry
	if (lastCat) {
		const c = cats.get(lastCat), s = summary(c);
		if (s.prio > 0 && c.playable >= 0) queued.add(c.id); else queued.delete(c.id);
		panes.categories?.querySelector(`tr[data-id="${lastCat}"]`)?.replaceWith(queueRow(c));
		lastCat = null;
	}
	if (/^c-?\d+$/.test(h)) {
		navigation(null);
		lastCat = +h.slice(1);
		return pane(h, p => categoryView(p, cats.get(lastCat)));
	}
	navigation(h);
	if (h == 'categories') pane(h, queueView);
	else if (h == 'pairs') pane(h, pairsView);
	else if (h == 'text') pane(h, textView);
	else if (h == 'pending') { panes.pending?.remove(); delete panes.pending; pane(h, pendingView); }
}
window.onhashchange = route;

// Categories queue
const queue = [...cats.values()].filter(c => c.playable >= 0 && c.id > 0).map(c => ({c, s: summary(c)})).filter(r => r.s.prio > 0).sort((x, y) => y.s.prio - x.s.prio);
const queued = new Set(queue.map(r => r.c.id));
function queueRow(c) {
	const s = summary(c), tr = document.createElement('tr');
	tr.dataset.id = c.id;
	tr.dataset.type = c.type;
	tr.dataset.topic = c.topic;
	tr.innerHTML = `<td>${catLink(c.id)}</td><td>${esc(c.type)}</td><td>${esc(c.topic)}</td><td class="num bad">${s.wrong || ''}</td><td class="num warn">${s.obscure || ''}</td><td class="num good">${s.fine || ''}</td>` +
		`<td class="num">${s.spelling || ''}</td><td class="num">${s.cand || ''}</td><td class="num">${s.prio.toFixed(1)}</td>`;
	return tr;
}
function queueView(p) {
	const rows = queue, options = k => [...new Set(rows.map(r => r.c[k]))].sort().map(v => `<option>${esc(v)}</option>`).join('');
	p.innerHTML = `<div class="filters">Type <select data-k="type"><option value="">all</option>${options('type')}</select> Topic <select data-k="topic"><option value="">all</option>${options('topic')}</select></div>` +
		'<table><thead><tr><th>Category</th><th>Type</th><th>Topic</th><th class="num">Wrong</th><th class="num">Obscure</th><th class="num">Fine</th><th class="num">Spelling</th><th class="num">Candidates</th><th class="num">Priority</th></tr></thead><tbody></tbody></table>';
	p.querySelector('tbody').append(...rows.map(r => queueRow(r.c)));
	p.onchange = () => {
		const f = Object.fromEntries([...p.querySelectorAll('select')].map(s => [s.dataset.k, s.value]));
		for (const tr of p.querySelectorAll('tbody tr')) tr.hidden = (f.type && tr.dataset.type != f.type) || (f.topic && tr.dataset.topic != f.topic);
	};
}

// Category view: members and candidates with their play data, to set levels, remove, rename and add
async function categoryView(p, c) {
	const M = (await members([c.id])).get(c.id);
	const on = games.filter(g => g.cats.has(c.id)), lv = {};
	for (const g of on) { const l = g.levels ? g.levels[c.topic] ?? '?' : 'before topics'; lv[l] = (lv[l] || 0) + 1; }
	const count = l => [...M.values()].filter(x => x == l).length;
	const playable = [[1, 'playable'], [0, 'chain blocking only'], [-1, 'off']].map(([v, label]) => `<label><input type="radio" name="playable" value="${v}"${c.playable == v ? ' checked' : ''}><span>${label}</span></label>`).join('');
	p.innerHTML = `<div class="cat"><div><div class="head"><h2>${esc(c.name)}</h2>` +
		`<p>${esc(c.type)} · ${esc(c.topic)} · <span class="seg">${playable}</span></p>` +
		`<p>${M.size} items: ${[1, 2, 3, 4].map(l => `${count(l)} ${levelName[l]}`).join(' · ')}. On ${on.length} boards; topic level then: ${Object.entries(lv).map(([l, n]) => `${levelName[l] || l} ${n}`).join(', ') || 'none'}</p></div>` +
		'<table><thead><tr><th>Item</th><th>Level</th><th></th><th></th><th class="num">Boards</th><th class="num" title="mean position found: 0% first merge of the category, 100% last">Found</th>' +
		'<th class="num" title="failed tries while on its own before the guessing onset, per board size, per board">Fails</th><th>Estimate</th><th title="open feedback: fine / obscure / wrong">Votes</th><th>Notes</th><th></th></tr></thead><tbody></tbody></table>' +
		'<div class="addrow"><input type="text" placeholder="Add an item" autocomplete="off"> <span class="seg add">' + [1, 2, 3, 4].map(l => `<label><input type="radio" name="add" value="${l}"><span>${levelName[l]}</span></label>`).join('') + '</span> <small class="status muted"></small></div>' +
		'</div><aside></aside></div>';
	const tbody = p.querySelector('tbody'), aside = p.querySelector('aside'), addInput = p.querySelector('.addrow input'), addStatus = p.querySelector('.addrow .status');
	// Kept-apart categories, to remove or add with a category search
	const keptApart = () => {
		const ids = [...apart].map(k => k.split(',').map(Number)).filter(ab => ab.includes(c.id)).map(([a, b]) => a == c.id ? b : a).filter(id => cats.has(id));
		aside.innerHTML = '<h3>Kept apart from</h3>' + (ids.map(id => `<div><button class="unapart" data-id="${id}" title="no longer apart">✕</button> ${catLink(id)}</div>`).join('') || '<span class="muted">none</span>') +
			'<div class="addapart"><input type="text" placeholder="Keep apart from…" autocomplete="off"></div>';
		catSearch(aside.querySelector('.addapart input'), async id => {
			const k = pairKey(c.id, id);
			if (id == c.id || apart.has(k)) return;
			await post('apart', {a: c.id, b: id, keep: 1});
			apart.add(k);
			votes.delete(k);
			const x = pairQueue.find(x => x.k == k);
			if (x) x.decided = true;
			keptApart();
		});
		for (const b of aside.querySelectorAll('.unapart')) b.onclick = async () => {
			await post('unapart', {a: c.id, b: b.dataset.id});
			apart.delete(pairKey(c.id, +b.dataset.id));
			keptApart();
		};
	};
	keptApart();

	const pend = pending.filter(x => x.category == c.name);
	const group = (title, ws) => ws.length ? [`<tr class="group"><td colspan="11">${title} (${ws.length})</td></tr>`, ...ws.map(row)] : [];
	let i = 0;
	function row(w) {
		const s = play.get(c.id)?.get(w), e = fb.get(c.id)?.get(w), nm = near.get(c.id)?.get(w), level = M.get(w), est = estimate(c, w), k = i++;
		const renamed = pend.find(x => x.other == w), added = pend.find(x => x.item == w && !x.other);
		const radios = ls => '<span class="seg">' + ls.map(l => `<label><input type="radio" name="r${k}" value="${l}"${level == l ? ' checked' : ''}><span>${levelName[l]}</span></label>`).join('') + '</span>';
		const notes = [];
		if (e) for (const [to, n] of e.spell) notes.push(`<button class="chip spell" data-to="${esc(to)}">→ ${esc(to)}${n > 1 ? ' ' + n + '×' : ''}</button>`);
		if (level == null && nm) notes.push(`near misses ${nm.ev.toFixed(1)} from ${nm.players.size} player(s), together on ${(wordGames.get(w) || []).filter(g => g.cats.has(c.id)).length} board(s)`);
		if (renamed) notes.push(`→ ${esc(renamed.item)} pending`);
		if (added) notes.push('pending');
		return `<tr data-w="${esc(w)}"${renamed || added ? ' class="done"' : ''}><td><button class="name">${esc(w)}</button></td><td class="lv">${added ? '' : radios([1, 2, 3])}</td><td class="lv">${added ? '' : radios([4])}</td>` +
			`<td class="x">${added ? '' : `<button class="remove" title="${level == null ? 'reject' : 'remove'}">✕</button>`}</td>` +
			`<td class="num">${s?.boards ?? ''}</td><td class="num">${s?.pos.length ? Math.round(100 * sum(s.pos) / s.pos.length) + '%' : ''}</td><td class="num">${s ? (s.fails / s.boards).toFixed(2) : ''}</td>` +
			`<td>${est == null ? '' : `<span class="bar" style="width:${Math.round(60 * Math.min(1, est / 1.5))}px" title="${est.toFixed(2)}"></span>`}</td>` +
			`<td>${e ? `<span class="good">${e.normal}</span> / <span class="warn">${e.obscure}</span> / <span class="bad">${e.none}</span>` : ''}</td><td class="notes">${notes.join(' ')}</td>` +
			`<td>${level != null && !renamed ? '<button class="rename" title="rename">✎</button>' : ''}</td></tr>`;
	}
	const items = [...M.keys()], disputed = items.filter(w => fb.get(c.id)?.get(w)?.none);
	const rest = items.filter(w => !disputed.includes(w)), withData = rest.filter(w => estimate(c, w) != null);
	const cand = [...candidates(c, M), ...pend.filter(x => !x.other).map(x => x.item)];
	const weight = w => { const e = fb.get(c.id)?.get(w); return (e ? e.normal + e.obscure : 0) + (near.get(c.id)?.get(w)?.ev || 0); };
	tbody.innerHTML = [
		...group('Disputed', disputed.sort((x, y) => fb.get(c.id).get(y).none - fb.get(c.id).get(x).none)),
		...group('Candidates', [...new Set(cand)].sort((x, y) => weight(y) - weight(x))),
		...group('Members by estimated difficulty', withData.sort((x, y) => estimate(c, x) - estimate(c, y))),
		...group('Members without play data', rest.filter(w => !withData.includes(w)).sort((x, y) => M.get(x) - M.get(y) || x.localeCompare(y))),
		'<tr class="group added" hidden><td colspan="11">Added</td></tr>'].join('');

	// Any decision on an item resolves its open feedback in this category
	const resolved = (tr, w) => { fb.get(c.id)?.delete(w); decided.add(c.id + '\n' + w); tr.classList.add('done'); };
	const note = (tr, text) => tr.cells[9].textContent = text;
	async function decide(tr, w, level) {
		if (await post('level', {cat: c.id, item: w, level}) == 'pending') {
			pending.push({item: w, category: c.name, other: '', level});
			note(tr, 'pending');
			navigation(null);
		} else M.set(w, level);
		resolved(tr, w);
	}
	p.onclick = async e => {
		const t = e.target, tr = t.closest('tbody tr'), w = tr?.dataset.w;
		if (t.name == 'playable') {
			await post('playable', {cat: c.id, playable: t.value});
			c.playable = +t.value;
		} else if (t.matches('tbody input[type=radio]')) {
			decide(tr, w, +t.value);
		} else if (t.matches('.addrow input[type=radio]')) {
			const v = addInput.value.trim();
			t.checked = false;
			if (!v) return;
			const added = document.createElement('tbody');
			added.innerHTML = row(v);
			const nr = added.firstChild;
			tbody.append(nr);
			tbody.querySelector('.added').hidden = false;
			nr.querySelector(`input[value="${t.value}"]`).checked = true;
			addInput.value = '';
			addStatus.textContent = '';
			decide(nr, v, +t.value);
		} else if (t.closest('.remove')) {
			await post('remove', {cat: c.id, item: w});
			M.delete(w);
			for (const r of tr.querySelectorAll('input[type=radio]')) r.checked = false;
			note(tr, 'removed');
			resolved(tr, w);
		} else if (t.closest('.rename')) {
			const cell = tr.cells[0];
			cell.innerHTML = `<input type="text" class="to" value="${esc(w)}" autocomplete="off">`;
			cell.style.position = 'relative';
			cell.firstChild.focus();
		} else if (t.closest('.spell')) rename(tr, w, t.closest('.spell').dataset.to);
		else if (t.closest('.name')) itemPanel(w);
	};
	async function rename(tr, w, to) {
		if (to == w) return;
		const level = M.get(w);
		if (await post('rename', {cat: c.id, item: w, to, level}) == 'pending') {
			pending.push({item: to, category: c.name, other: w, level});
			note(tr, `→ ${to} pending`);
			navigation(null);
		} else {
			M.delete(w);
			if (!M.has(to)) M.set(to, level);
			note(tr, `renamed to ${to}`);
		}
		tr.cells[0].innerHTML = `<button class="name">${esc(w)}</button>`;
		resolved(tr, w);
	}
	p.oninput = e => {
		if (e.target == addInput) suggest(addInput, addStatus);
		else if (e.target.matches('input.to')) suggest(e.target);
	};
	p.onkeydown = e => {
		const t = e.target;
		if (!t.matches('input.to')) return;
		const tr = t.closest('tr'), w = tr.dataset.w;
		if (e.key == 'Enter') rename(tr, w, t.value.trim());
		if (e.key == 'Escape') tr.cells[0].innerHTML = `<button class="name">${esc(w)}</button>`;
	};
	// An item's spellings and categories, loaded on click
	async function itemPanel(w) {
		const rows = await get('item&item=' + encodeURIComponent(w)), by = new Map();
		for (const [item, id, , level] of rows) { if (!by.has(item)) by.set(item, []); by.get(item).push([+id, level]); }
		aside.innerHTML = `<button class="back">← kept apart</button>` + [...by].map(([item, cs]) => `<h3>${esc(item)}</h3>` +
			cs.map(([id, level]) => `<div>${catLink(id)} <small class="muted">${levelName[level]}</small></div>`).join('')).join('') + (by.size ? '' : `<h3>${esc(w)}</h3><span class="muted">in no category</span>`);
		aside.querySelector('.back').onclick = keptApart;
	}
}

// Pairs: twenty at a time, with their members fetched once per category
function pairsView(p) {
	p.innerHTML = '<div class="list"></div><button class="more">More</button>';
	const list = p.querySelector('.list'), more = p.querySelector('.more');
	let shown = 0;
	async function page() {
		const batch = pairQueue.slice(shown, shown += 20), ids = [...new Set(batch.flatMap(x => x.ab))];
		more.hidden = shown >= pairQueue.length;
		const ms = await members(ids);
		for (const x of batch) {
			const [A, B] = x.ab.map(id => new Set(ms.get(id).keys()));
			const only = (S, T) => [...S].filter(w => !T.has(w)), both = [...A].filter(w => B.has(w));
			const stats = [x.keep || x.fine ? `keep apart ${x.keep} · fine ${x.fine}` : '', x.boards ? `confusions ${x.obs.toFixed(1)} vs ${x.exp.toFixed(1)} expected on ${x.boards} boards` : '',
				`${both.length} shared (${Math.round(100 * both.length / Math.min(A.size, B.size))}%)`].filter(Boolean).join(' · ');
			const lst = (title, ws) => `<p class="lst"><b>${title} (${ws.length})</b>${ws.map(esc).join(', ')}</p>`;
			// The shared column is 10% wide plus the shared part of all items in %, up to 50%
			const mid = Math.round(Math.min(50, 10 + 100 * both.length / new Set([...A, ...B]).size));
			const div = document.createElement('div');
			div.className = 'pair';
			div.innerHTML = `<div class="ph"><span>${catLink(x.ab[0])} ↔ ${catLink(x.ab[1])}</span><span class="stats">${stats}</span><span class="act"><button data-keep="1">Keep apart</button><button data-keep="0">Fine together</button></span></div>` +
				`<div class="cols" style="grid-template-columns: 1fr ${mid}% 1fr">` + lst('Only ' + esc(cats.get(x.ab[0]).name), only(A, B)) + lst('Both', both) + lst('Only ' + esc(cats.get(x.ab[1]).name), only(B, A)) + '</div>';
			div.onclick = async e => {
				if (e.target.closest('.lst')) return e.target.closest('.lst').classList.toggle('all');
				const keep = e.target.dataset.keep;
				if (keep == null) return;
				await post('apart', {a: x.ab[0], b: x.ab[1], keep});
				(keep == '1' ? apart : fineDecided).add(x.k);
				votes.delete(x.k);
				x.decided = true;
				div.className = 'pair done';
				div.innerHTML = `${catLink(x.ab[0])} ↔ ${catLink(x.ab[1])}: ${keep == '1' ? 'kept apart' : 'fine together'}`;
				navigation('pairs');
			};
			list.append(div);
		}
	}
	more.onclick = page;
	page();
}

// Text feedback, newest first; names of the board's categories link to their view, ignoring case, quote marks and "..." for "…"
const quotes = /["'„“”‚‘’«»]/g;
const pattern = name => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/…/g, '(?:…|\\.\\.\\.)').replace(quotes, `["'„“”‚‘’«»]`);
function linked(t) {
	const cs = [...(gameBySeed.get(t.seed)?.cats || [])].filter(id => id > 0).map(id => cats.get(id)).sort((x, y) => y.name.length - x.name.length);
	if (!cs.length) return esc(t.value);
	const one = cs.map(c => new RegExp('^' + pattern(c.name) + '$', 'i'));
	return t.value.split(new RegExp('(' + cs.map(c => pattern(c.name)).join('|') + ')', 'i')).map((part, i) => i % 2 ? `<a href="#c${cs[one.findIndex(r => r.test(part))].id}">${esc(part)}</a>` : esc(part)).join('');
}
function textView(p) {
	for (const t of [...texts].sort((x, y) => y.t - x.t)) {
		const div = document.createElement('div');
		div.className = 'text';
		div.innerHTML = `<div><small class="muted">${new Date(t.t * 1000).toLocaleDateString()} · ${esc(t.name || 'guest')}</small><p>${linked(t)}</p></div><div><button>Done</button></div>`;
		div.querySelector('button').onclick = async () => {
			await post('done', {seed: t.seed, user: t.user});
			t.done = true;
			div.classList.add('done');
			navigation('text');
		};
		p.append(div);
	}
}

// Pending names, to be checked against all other categories before they are approved
function pendingView(p) {
	const list = [...pending];
	p.innerHTML =`<p class="muted">New names and the category they are planned for, tab-separated.</p><textarea rows="${Math.min(20, pending.length + 1)}" readonly>${esc(pending.map(x => x.item + '\t' + x.category).join('\n'))}</textarea>` +
		'<table><thead><tr><th>Item</th><th>Category</th><th>Renames</th><th>Level</th><th></th></tr></thead><tbody>' +
		pending.map((x, i) => `<tr data-i="${i}"><td>${esc(x.item)}</td><td>${byName.has(x.category) ? catLink(byName.get(x.category).id) : esc(x.category)}</td><td>${esc(x.other)}</td><td>${levelName[x.level]}</td>` +
			'<td><button data-a="approve">Approve</button> <button data-a="drop">Drop</button></td></tr>').join('') + '</tbody></table>';
	p.onclick = async e => {
		const a = e.target.dataset.a;
		if (!a) return;
		const tr = e.target.closest('tr'), x = list[tr.dataset.i], cat =byName.get(x.category).id, M = memberMaps.get(cat);
		await post(a, {cat, item: x.item, other: x.other, level: x.level});
		if (a == 'approve' && M) {
			M.delete(x.other);
			M.set(x.item, x.level);
		}
		pending.splice(pending.indexOf(x), 1);
		tr.className = 'done';
		tr.cells[4].textContent = a == 'approve' ? 'approved' : 'dropped';
		// The category's view is built again from the data already here
		panes['c' + cat]?.remove();
		delete panes['c' + cat];
		navigation('pending');
	};
}

route();
</script>
<?php endif ?>
</body>
</html>
