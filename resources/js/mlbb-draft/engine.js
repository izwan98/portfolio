/**
 * Draft advisor. Pure functions over:
 *   heroes:   Map<id, {id, name, roles[], lanes[], strong[], weak[], assist[]}>
 *   stats:    { [id]: { wr, pr, br } }  (rates 0..1 for the selected rank)
 *   matchups: { [id]: { counteredBy, beats, goodWith, badWith } }  ([heroId, Δ win-rate pts])
 *
 * Every score is a sum of named parts, so the UI can explain each recommendation.
 */

export const LANES = ['exp', 'jungle', 'mid', 'gold', 'roam'];
export const LANE_LABEL = { exp: 'EXP', jungle: 'Jungle', mid: 'Mid', gold: 'Gold', roam: 'Roam' };

/** Heroes that heal/sustain enough that someone should buy anti-heal. */
const SUSTAIN = new Set(['Estes', 'Floryn', 'Rafaela', 'Angela', 'Kalea', 'Ruby', 'Alucard', 'Uranus', 'Thamuz', 'Esmeralda', 'Fredrinn', 'Yu Zhong', 'Alpha', 'Aldous']);

/**
 * Turn a pick & ban template into the ordered list of turns for this draft.
 *
 * Template steps say `first`/`second` team; we map that to ally/enemy from `weFirst`.
 * Each turn gets: kind, team, index (player 0–4), turn (1-based among its kind),
 * and block — consecutive ban turns form one ban round (block 1, 2, …); a ban marked
 * `newRound` starts the next round even without picks in between.
 */
export function buildSequence(template, weFirst) {
    const map = { first: weFirst ? 'ally' : 'enemy', second: weFirst ? 'enemy' : 'ally' };
    const count = { ban: 0, pick: 0 };
    let block = 0;
    let prev = null;

    return (template?.steps ?? []).map((s) => {
        if (s.action === 'ban' && (prev !== 'ban' || s.newRound)) block++;
        prev = s.action;
        return { kind: s.action, team: map[s.team], index: s.player - 1, turn: ++count[s.action], block: s.action === 'ban' ? block : 0 };
    });
}

/** Problems with a template's turns (mirrors the server rules). Empty array = valid. */
export function templateProblems(steps) {
    const problems = [];
    if (!steps.length) problems.push('Add at least one turn.');
    if (steps.length > 30) problems.push('Keep it to 30 turns or fewer.');

    for (const [team, label] of [['first', 'First team'], ['second', 'Second team']]) {
        for (const action of ['pick', 'ban']) {
            const players = steps.filter((s) => s.team === team && s.action === action).map((s) => s.player);
            const dupes = [...new Set(players.filter((p, i) => players.indexOf(p) !== i))];
            dupes.forEach((p) => problems.push(`${label}: player ${p} has more than one ${action}.`));
            if (action === 'pick' && new Set(players).size !== 5) {
                problems.push(`${label} must pick 5 times (one per player) — it has ${players.length}.`);
            }
        }
    }
    return problems;
}

/** Next player number (1–5) that hasn't done `action` yet for `team`, or 1 if all have. */
export function nextPlayer(steps, action, team) {
    const used = new Set(steps.filter((s) => s.action === action && s.team === team).map((s) => s.player));
    for (let p = 1; p <= 5; p++) if (!used.has(p)) return p;
    return 1;
}

/** Append turns in a snake (A, B, B, A, A, B…) or strict alternation (A, B, A, B…). */
export function addTurns(steps, action, perTeam, pattern = 'snake', start = 'first') {
    const other = start === 'first' ? 'second' : 'first';
    const out = [...steps];
    const used = { first: 0, second: 0 };
    for (let turn = 0; used.first + used.second < perTeam * 2; turn++) {
        const team = pattern === 'snake' ? (Math.floor((turn + 1) / 2) % 2 === 0 ? start : other) : (turn % 2 === 0 ? start : other);
        if (used[team] < perTeam) {
            out.push({ action, team, player: nextPlayer(out, action, team) });
            used[team]++;
        }
    }
    return out;
}

const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
const pct = (n) => (n >= 0 ? '+' : '') + n.toFixed(1) + '%';

export function prepareStats(stats) {
    const rows = Object.values(stats);
    const prs = rows.map((s) => s.pr).sort((a, b) => a - b);
    return {
        brMax: Math.max(0.01, ...rows.map((s) => s.br)),
        prMax: Math.max(0.001, ...prs),
        prMedian: prs[Math.floor(prs.length / 2)] || 0.005,
    };
}

/** Win rate shrunk toward 50% for rarely-picked heroes (small samples are noisy). */
export function metaPoints(id, ctx) {
    const s = ctx.stats[id];
    if (!s) return 0;
    const confidence = clamp(s.pr / ctx.norm.prMedian, 0.3, 1);
    return (s.wr - 0.5) * 100 * confidence * 0.7;
}

/** 0..1 — how much players want this hero right now (bans weigh more than picks). */
export function popularity(id, ctx) {
    const s = ctx.stats[id];
    if (!s) return 0;
    return 0.6 * (s.br / ctx.norm.brMax) + 0.4 * (s.pr / ctx.norm.prMax);
}

function find(list, id) {
    const hit = list?.find((x) => x[0] === id);
    return hit ? hit[1] : null;
}

/** Win-rate delta for hero `h` when facing hero `e` (positive = good for h). */
export function matchup(h, e, ctx) {
    const me = ctx.matchups[e];
    if (me) {
        const into = find(me.counteredBy, h);
        if (into !== null) return { d: into, src: 'data' };
        const bad = find(me.beats, h);
        if (bad !== null) return { d: bad, src: 'data' };
    }
    const mh = ctx.matchups[h];
    if (mh) {
        const beats = find(mh.beats, e);
        if (beats !== null) return { d: -beats, src: 'data' };
        const lost = find(mh.counteredBy, e);
        if (lost !== null) return { d: -lost, src: 'data' };
    }
    const he = ctx.heroes.get(e);
    const hh = ctx.heroes.get(h);
    if (he?.weak.includes(h) || hh?.strong.includes(e)) return { d: 1.5, src: 'official' };
    if (he?.strong.includes(h) || hh?.weak.includes(e)) return { d: -1.5, src: 'official' };
    return null;
}

/** Win-rate delta for `h` playing alongside `a`. */
export function synergy(h, a, ctx) {
    const ma = ctx.matchups[a];
    if (ma) {
        const good = find(ma.goodWith, h);
        if (good !== null) return good;
        const bad = find(ma.badWith, h);
        if (bad !== null) return bad;
    }
    const mh = ctx.matchups[h];
    if (mh) {
        const good = find(mh.goodWith, a);
        if (good !== null) return good;
        const bad = find(mh.badWith, a);
        if (bad !== null) return bad;
    }
    if (ctx.heroes.get(a)?.assist.includes(h) || ctx.heroes.get(h)?.assist.includes(a)) return 1;
    return null;
}

/** The lane each role usually plays — used to order a hero's possible lanes. */
const ROLE_LANE = { marksman: 'gold', mage: 'mid', assassin: 'jungle', tank: 'roam', support: 'roam', fighter: 'exp' };

/** A hero's lanes, most natural first (its main role's lane leads). */
export function lanesByPreference(hero) {
    if (!hero) return [];
    const ranked = hero.roles.map((r) => ROLE_LANE[r]).filter(Boolean);
    return [...hero.lanes].sort((a, b) => {
        const ia = ranked.indexOf(a);
        const ib = ranked.indexOf(b);
        return (ia < 0 ? 9 : ia) - (ib < 0 ? 9 : ib);
    });
}

/** Lanes taken by a team's picks (explicit lane, else the hero's most natural free lane). */
export function assignLanes(picks, heroes) {
    const taken = picks.filter((p) => p?.id && p.lane).map((p) => p.lane);
    return picks.map((p) => {
        if (!p?.id) return null;
        if (p.lane) return p.lane;
        const options = lanesByPreference(heroes.get(p.id));
        const lane = options.find((l) => !taken.includes(l)) || options[0] || null;
        if (lane) taken.push(lane);
        return lane;
    });
}

/** Lanes nobody has taken or declared yet (ignoring slot `except`, the one we're advising). */
export function openLanes(picks, heroes, except = -1) {
    const assigned = assignLanes(picks, heroes);
    const taken = picks.map((p, i) => (i === except ? null : p?.id ? assigned[i] : p?.lane)).filter(Boolean);
    return LANES.filter((l) => !taken.includes(l));
}

/**
 * Score every available hero for an ally pick.
 *
 * @param {object} ctx  { heroes, stats, norm, matchups, unavailable:Set, allyIds[], enemyIds[], allyPicks[] }
 * @param {object} opts { forMe, myLanes[], pool[], firstPick, lastPick }
 */
export function recommendPicks(ctx, opts = {}) {
    const open = openLanes(ctx.allyPicks, ctx.heroes, opts.slot ?? -1);
    const target = opts.targetLanes ?? [];
    const counterWeight = opts.lastPick ? 1.5 : 1;
    const out = [];

    for (const hero of ctx.heroes.values()) {
        const h = hero.id;
        if (ctx.unavailable.has(h)) continue;

        const reasons = [];
        let score = 0;

        const meta = metaPoints(h, ctx);
        score += meta;
        if (Math.abs(meta) >= 1.5) reasons.push({ kind: meta > 0 ? 'good' : 'bad', text: `${((ctx.stats[h]?.wr ?? 0.5) * 100).toFixed(1)}% win rate at this rank`, pts: meta });

        let counters = 0;
        for (const e of ctx.enemyIds) {
            const m = matchup(h, e, ctx);
            if (!m) continue;
            const pts = clamp(m.d, -6, 6) * counterWeight;
            counters += pts;
            if (Math.abs(m.d) >= 1) reasons.push({ kind: m.d > 0 ? 'good' : 'bad', text: `${pct(m.d)} vs ${ctx.heroes.get(e)?.name}${m.src === 'official' ? ' (official)' : ''}`, pts });
        }
        score += counters;

        for (const a of ctx.allyIds) {
            const d = synergy(h, a, ctx);
            if (d === null) continue;
            const pts = clamp(d, -6, 4) * 0.8;
            score += pts;
            if (Math.abs(d) >= 1) reasons.push({ kind: d > 0 ? 'good' : 'bad', text: `${d > 0 ? 'Synergy' : 'Clashes'} with ${ctx.heroes.get(a)?.name} (${pct(d)})`, pts });
        }

        const fits = hero.lanes.filter((l) => open.includes(l));
        if (ctx.allyIds.length || ctx.allyPicks.some((p) => !p.id && p.lane)) {
            if (fits.length) {
                score += 3;
                reasons.push({ kind: 'good', text: `Fills ${fits.map((l) => LANE_LABEL[l]).join('/')}`, pts: 3 });
            } else {
                score -= 8;
                reasons.push({ kind: 'bad', text: `${hero.lanes.map((l) => LANE_LABEL[l]).join('/')} already taken`, pts: -8 });
            }
        }

        if (target.length) {
            if (hero.lanes.some((l) => target.includes(l))) {
                score += 4;
            } else {
                score -= 6;
                reasons.push({ kind: 'bad', text: opts.forMe ? 'Not your lane' : 'Not their declared lane', pts: -6 });
            }
        }

        if (opts.forMe && opts.pool?.includes(h)) {
            score += 5;
            reasons.push({ kind: 'good', text: 'In your hero pool', pts: 5 });
        }

        if (opts.firstPick && hero.lanes.length + hero.roles.length > 2) {
            score += 1.5;
            reasons.push({ kind: 'good', text: 'Flexible — hides your plan', pts: 1.5 });
        }

        // Taking a hero the enemy wants is worth a little on its own.
        const deny = popularity(h, ctx) * 2;
        score += deny;
        if (popularity(h, ctx) > 0.35) reasons.push({ kind: 'good', text: `Contested — ${Math.round((ctx.stats[h]?.br ?? 0) * 100)}% ban · ${((ctx.stats[h]?.pr ?? 0) * 100).toFixed(1)}% pick`, pts: deny });

        reasons.sort((a, b) => Math.abs(b.pts) - Math.abs(a.pts));
        out.push({ id: h, score, reasons, counters });
    }

    return out.sort((a, b) => b.score - a.score);
}

/**
 * The team's best options for every lane it still needs (captain's view): e.g. with mid and
 * jungle taken, you get EXP, Roam and Gold columns. Each hero is scored with counters vs the
 * enemy picks, synergy with ours, win rate at the rank and how contested it is (pick/ban rate).
 *
 * @returns {Array<{lane: string, picks: Array}>}
 */
export function recommendByLane(ctx, opts = {}, perLane = 3) {
    const all = recommendPicks(ctx, opts);
    const open = openLanes(ctx.allyPicks, ctx.heroes);
    return open.map((lane) => ({
        lane,
        picks: all.filter((r) => ctx.heroes.get(r.id)?.lanes.includes(lane)).slice(0, perLane),
    }));
}

/** Heroes the enemy is likely to pick next: popular, strong, fill their lanes, counter us. */
export function predictEnemy(ctx) {
    const open = openLanes(ctx.enemyPicks, ctx.heroes);
    const out = [];

    for (const hero of ctx.heroes.values()) {
        const h = hero.id;
        if (ctx.unavailable.has(h)) continue;

        const reasons = [];
        let score = popularity(h, ctx) * 8 + metaPoints(h, ctx) * 0.6;

        if (ctx.enemyIds.length) {
            score += hero.lanes.some((l) => open.includes(l)) ? 2 : -6;
        }

        for (const a of ctx.allyIds) {
            const m = matchup(h, a, ctx);
            if (m && m.d > 0) {
                score += clamp(m.d, 0, 6);
                if (m.d >= 1) reasons.push(`Counters your ${ctx.heroes.get(a)?.name} (${pct(m.d)})`);
            }
        }

        const s = ctx.stats[h];
        if (s?.br > 0.25) reasons.unshift(`Banned in ${Math.round(s.br * 100)}% of games`);
        else if (s) reasons.unshift(`${(s.wr * 100).toFixed(1)}% win rate · ${(s.pr * 100).toFixed(1)}% pick`);

        out.push({ id: h, score, reasons });
    }

    return out.sort((a, b) => b.score - a.score);
}

/** What to ban: high ban/pick/win rates, plus heroes that counter your pool or picks. */
export function recommendBans(ctx, pool = [], opts = {}) {
    const protect = new Set([...pool, ...ctx.allyIds]);
    const out = [];

    for (const hero of ctx.heroes.values()) {
        const h = hero.id;
        if (ctx.unavailable.has(h) || protect.has(h)) continue;

        const s = ctx.stats[h];
        const reasons = [];
        let score = popularity(h, ctx) * 10 + metaPoints(h, ctx) * 0.8;

        if (s?.br > 0.2) reasons.push(`Banned in ${Math.round(s.br * 100)}% of games`);
        if (s && s.wr > 0.53) reasons.push(`${(s.wr * 100).toFixed(1)}% win rate`);

        for (const p of [...pool, ...ctx.allyIds]) {
            const m = matchup(h, p, ctx);
            if (m && m.d > 0) {
                score += clamp(m.d, 0, 6) * 0.8;
                if (m.d >= 1) reasons.push(`Counters your ${ctx.heroes.get(p)?.name} (${pct(m.d)})`);
            }
        }

        // Wave 1 is blind: a hero the enemy is also likely to ban risks a wasted (duplicate) ban.
        if (opts.blind && s?.br > 0.3) {
            score *= 1 - 0.45 * s.br;
            reasons.push(`Enemy may ban it too (${Math.round(s.br * 100)}%) — risk of a wasted ban`);
        }

        if (!reasons.length && s) reasons.push(`${(s.wr * 100).toFixed(1)}% win · ${(s.pr * 100).toFixed(1)}% pick`);
        out.push({ id: h, score, reasons });
    }

    return out.sort((a, b) => b.score - a.score);
}

/** Composition warnings for a team (and what to do about the enemy's). */
export function teamCheck(myPicks, enemyPicks, heroes) {
    const notes = [];
    const mine = myPicks.filter((p) => p?.id).map((p) => heroes.get(p.id)).filter(Boolean);
    const theirs = enemyPicks.filter((p) => p?.id).map((p) => heroes.get(p.id)).filter(Boolean);
    const lanes = assignLanes(myPicks, heroes).filter(Boolean);
    const roles = new Set(mine.flatMap((h) => h.roles));

    const dupes = lanes.filter((l, i) => lanes.indexOf(l) !== i);
    for (const l of new Set(dupes)) notes.push({ level: 'warn', text: `Two ${LANE_LABEL[l]} picks — someone has to swap lanes.` });

    if (mine.length >= 3) {
        const missing = LANES.filter((l) => !lanes.includes(l));
        if (missing.length && mine.length + missing.length <= 5) {
            notes.push({ level: 'info', text: `Still needed: ${missing.map((l) => LANE_LABEL[l]).join(', ')}.` });
        }
        if (!roles.has('tank') && !roles.has('support') && !lanes.includes('roam')) {
            notes.push({ level: 'warn', text: 'No tank or roamer yet — you will lack vision, crowd control and someone to start fights.' });
        }
    }

    if (mine.length >= 4 && !roles.has('mage')) {
        notes.push({ level: 'warn', text: 'All physical damage — the enemy can stack armor. Consider a mage or magic fighter.' });
    }
    if (mine.length >= 4 && !roles.has('marksman') && !roles.has('mage')) {
        notes.push({ level: 'info', text: 'Little sustained damage — plan to end fights fast or snowball early.' });
    }

    const healers = theirs.filter((h) => SUSTAIN.has(h.name));
    if (healers.length) {
        notes.push({ level: 'tip', text: `Enemy sustain from ${healers.map((h) => h.name).join(', ')} — buy anti-heal (Dominance Ice, Sea Halberd or Necklace of Durance).` });
    }

    const enemyRoles = new Set(theirs.flatMap((h) => h.roles));
    if (theirs.length >= 3 && enemyRoles.has('assassin')) {
        notes.push({ level: 'tip', text: 'Enemy has assassins — protect your marksman and mage, and consider a peel tank or Winter Crown/Wind of Nature.' });
    }

    if (!notes.length && mine.length) notes.push({ level: 'ok', text: 'Composition looks balanced so far.' });

    return notes;
}
