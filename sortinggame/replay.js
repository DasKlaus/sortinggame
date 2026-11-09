// Replay of sorting games, shared by stats.php and curate.php. A game is {seed, n, created, finished, items: [[word, category], ...], att: [[a, b, success, time, user], ...]}.
// Sets per game: trace (a record per attempt with the cluster heads a, b, their categories and sizes before it, progress, the chance p that a random pair of clusters matches,
// and the seconds since the player's previous attempt), oc (per category its clusters, merges and mistakes), onset (guessing onset), and totals. Returns all attempt records.
function replay(games) {
	const atts = [];
	for (const g of games) {
		const n = g.n, N = n * n, cat = g.items.map(x => x[1]);
		const head = [...Array(N).keys()], members = head.map(i => [i]), last = {};
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
		// Guessing onset: the first stretch of 10 attempts whose success rate is at most twice that of random pairs
		g.onset = null;
		for (let i = 9; i < g.trace.length && g.onset == null; i++) {
			const w = g.trace.slice(i - 9, i + 1);
			if (w.reduce((x, r) => x + r.s, 0) / 10 <= 2 * w.reduce((x, r) => x + r.p, 0) / 10) g.onset = i - 9;
		}
		g.onsetProg = g.onset == null ? null : g.trace[g.onset].prog;
	}
	return atts;
}

// Mistakes between two categories against the number expected if a game's mistakes paired its categories at random, given how often each was involved.
// Each board counts once: games on the same board are averaged. Returns a map from the sorted pair "x,y" to {obs, exp, raw, boards: Set of seeds}.
function confusions(games) {
	const pairs = new Map(), perSeed = new Map(), key = (x, y) => x < y ? x + ',' + y : y + ',' + x;
	const pair = k => pairs.get(k) || pairs.set(k, {obs: 0, exp: 0, raw: 0, boards: new Set()}).get(k);
	for (const g of games) perSeed.set(g.seed, (perSeed.get(g.seed) || 0) + 1);
	for (const g of games.filter(g => g.fails)) {
		const inv = new Map(), w = 1 / perSeed.get(g.seed);
		for (const r of g.trace.filter(r => !r.s)) {
			for (const c of [r.ca, r.cb]) inv.set(c, (inv.get(c) || 0) + 1);
			const p = pair(key(r.ca, r.cb));
			p.obs += w; p.raw++; p.boards.add(g.seed);
		}
		const cs = [...inv.keys()];
		for (let i = 0; i < cs.length; i++) for (let j = i + 1; j < cs.length; j++)
			pair(key(cs[i], cs[j])).exp += w * inv.get(cs[i]) * inv.get(cs[j]) / (2 * g.fails);
	}
	return pairs;
}
