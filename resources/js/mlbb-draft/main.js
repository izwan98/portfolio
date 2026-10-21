import Alpine from 'alpinejs';
import { LANE_LABEL, LANES, addTurns, assignLanes, buildSequence, nextPlayer, openLanes, predictEnemy, prepareStats, recommendBans, recommendByLane, recommendPicks, teamCheck, templateProblems } from './engine.js';

const CONFIG = JSON.parse(document.getElementById('mlbb-config').textContent);
const ROLES = ['tank', 'fighter', 'assassin', 'mage', 'marksman', 'support'];
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const store = {
    get(key, fallback) {
        try {
            const v = localStorage.getItem('mlbb-draft:' + key);
            return v === null ? fallback : JSON.parse(v);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem('mlbb-draft:' + key, JSON.stringify(value));
        } catch {
            // storage unavailable — settings just won't persist
        }
    },
};

/** Ban slots are indexed by player (0–4); the template decides which ones are used. */
const emptyDraft = (templateId) => ({
    templateId,
    bans: { ally: Array(5).fill(null), enemy: Array(5).fill(null) },
    picks: { ally: Array.from({ length: 5 }, () => ({ id: null, lane: null })), enemy: Array.from({ length: 5 }, () => ({ id: null, lane: null })) },
    active: null,
    bansSkipped: false,
});

const emptyEditor = () => ({ open: false, id: null, name: '', description: '', author: store.get('author', ''), blindBans: true, steps: [], saving: false, error: null });

/** Smaller, cached portraits via the image proxy the API already uses. */
export function portrait(url, size = 96) {
    if (!url) return '';
    return url.includes('wsrv.nl/') ? `${url}&w=${size}&h=${size}&fit=cover&output=webp` : url;
}

async function send(method, url, body, headers = {}) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...headers },
        body: body ? JSON.stringify(body) : undefined,
    });
    let data = null;
    try {
        data = await res.json();
    } catch {
        // empty / non-JSON body
    }
    if (!res.ok) {
        const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
        throw new Error(firstError || data?.message || (res.status === 429 ? 'Too many saves — try again later.' : res.status === 419 ? 'Session expired — refresh the page.' : 'Something went wrong.'));
    }
    return data;
}

Alpine.data('draft', () => ({
    ranks: CONFIG.ranks,
    windows: CONFIG.windows,
    lanes: LANES,
    roles: ROLES,
    laneLabel: LANE_LABEL,

    // settings (persisted)
    rankKey: store.get('rank', 'mythic'),
    days: store.get('days', CONFIG.defaultWindow),
    weFirst: store.get('weFirst', true),
    pool: store.get('pool', []),
    templateChoice: store.get('template', 'auto'),

    // templates
    templates: CONFIG.templates,
    myKeys: store.get('templateKeys', {}),
    showTemplates: false,
    templateQuery: '',
    ed: emptyEditor(),
    copiedLink: null,

    // data
    loading: true,
    error: null,
    heroes: new Map(),
    heroList: [],
    stats: {},
    norm: { brMax: 1, prMax: 1, prMedian: 0.01 },
    updatedAt: null,
    matchups: {},
    pendingMatchups: new Set(),

    // draft
    d: null,
    history: [],
    advice: { pick: [], byLane: [], enemy: [], enemyNeeds: [], bans: [], check: [], rank: new Map(), scores: new Map() },

    // ui
    q: '',
    roleFilter: '',
    laneFilter: '',
    sort: 'smart',
    tab: 'pick',
    showPool: false,
    poolQuery: '',

    async init() {
        const shared = new URLSearchParams(location.search).get('template');
        if (shared && this.templates.some((t) => t.id === shared)) this.templateChoice = shared;

        this.d = store.get('draft', null);
        if (!this.d || !Array.isArray(this.d.bans?.ally) || this.d.bans.ally.length !== 5 || this.d.templateId !== this.template.id) {
            this.d = emptyDraft(this.template.id);
        }
        if (!this.d.active) this.advance();
        this.autoTab();

        for (const key of ['rankKey', 'days']) {
            this.$watch(key, () => this.onFiltersChanged(key));
        }
        this.$watch('weFirst', (v) => {
            store.set('weFirst', v);
            // Whoever picks first also bans first — re-aim if nothing has happened yet.
            if (!this.bannedIds.length && !this.allyIds.length && !this.enemyIds.length) this.advance();
        });
        this.$watch('pool', (v) => {
            store.set('pool', v);
            this.loadMatchups();
        });
        this.$watch('templateChoice', (v) => {
            store.set('template', v);
            this.applyTemplate();
        });

        // Re-run the advisor whenever anything it reads changes.
        Alpine.effect(() => this.recompute());

        await this.load();
    },

    get rank() {
        return this.ranks.find((r) => r.key === this.rankKey) ?? this.ranks[6];
    },

    async onFiltersChanged(key) {
        store.set(key === 'rankKey' ? 'rank' : key, this[key]);
        if (key === 'rankKey' && this.templateChoice === 'auto') this.applyTemplate();
        this.matchups = {};
        await this.load();
    },

    async load() {
        this.loading = true;
        this.error = null;
        try {
            const res = await fetch(`/mlbb-draft/data?rank=${this.rank.api}&days=${this.days}`, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Could not load hero data.');

            this.heroList = data.heroes;
            this.heroes = new Map(data.heroes.map((h) => [h.id, h]));
            this.stats = data.stats;
            this.norm = prepareStats(data.stats);
            this.updatedAt = data.updatedAt;
            await this.loadMatchups();
        } catch (e) {
            this.error = e.message;
        } finally {
            this.loading = false;
        }
    },

    /** Fetch counters/synergy for every hero in the draft or your pool that we don't have yet. */
    async loadMatchups() {
        const want = [...this.allyIds, ...this.enemyIds, ...this.pool].filter((id) => !(id in this.matchups) && !this.pendingMatchups.has(id));
        if (!want.length) return;

        want.forEach((id) => this.pendingMatchups.add(id));
        try {
            const res = await fetch(`/mlbb-draft/matchups?rank=${this.rank.api}&days=${this.days}&ids=${want.join(',')}`, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                this.matchups = { ...this.matchups, ...data.matchups };
            }
        } finally {
            want.forEach((id) => this.pendingMatchups.delete(id));
        }
    },

    // ------------------------------------------------------------- template

    /** The template in use: the one you chose, or the built-in for your rank ("Auto"). */
    get template() {
        const byRank = this.templates.find((t) => t.id === this.rank.template) ?? this.templates[0];
        if (this.templateChoice === 'auto') return byRank;
        return this.templates.find((t) => t.id === this.templateChoice) ?? byRank;
    },

    get seq() {
        return buildSequence(this.template, this.weFirst);
    },

    applyTemplate() {
        if (this.d && this.d.templateId === this.template.id) return;
        if (this.d) this.history.push(JSON.stringify(this.d));
        this.d = emptyDraft(this.template.id);
        this.advance();
        this.autoTab();
        this.save();
    },

    get builtInTemplates() {
        return this.templates.filter((t) => t.builtIn);
    },

    get communityTemplates() {
        return this.templates.filter((t) => !t.builtIn);
    },

    get filteredTemplates() {
        const q = this.templateQuery.trim().toLowerCase();
        return this.templates.filter((t) => !q || [t.name, t.description, t.author].some((v) => String(v || '').toLowerCase().includes(q)));
    },

    templateSummary(t) {
        const bans = { first: 0, second: 0 };
        t.steps.forEach((s) => s.action === 'ban' && bans[s.team]++);
        const total = bans.first + bans.second;
        return `${total ? total + ' bans' : 'No bans'} · ${t.steps.length} turns${t.blindBans ? ' · blind bans' : ''}`;
    },

    canEdit(t) {
        return !t.builtIn && !!this.myKeys[t.id];
    },

    useTemplate(id) {
        this.templateChoice = id;
        this.showTemplates = false;
    },

    shareUrl(t) {
        return `${location.origin}/mlbb-draft?template=${encodeURIComponent(t.id)}`;
    },

    async copyLink(t) {
        try {
            await navigator.clipboard.writeText(this.shareUrl(t));
            this.copiedLink = t.id;
            setTimeout(() => (this.copiedLink = null), 1500);
        } catch {
            // clipboard blocked
        }
    },

    async refreshTemplates() {
        try {
            const data = await send('GET', '/mlbb-draft/templates');
            this.templates = data.templates;
        } catch {
            // keep what we have
        }
    },

    // --------------------------------------------------------------- editor

    openEditor(base = null, edit = false) {
        this.ed = {
            ...emptyEditor(),
            open: true,
            id: edit ? base.id : null,
            name: edit ? base.name : base ? `${base.name} (copy)`.slice(0, 60) : '',
            description: base?.description ?? '',
            author: edit ? base.author : store.get('author', ''),
            blindBans: base ? !!base.blindBans : true,
            steps: base ? base.steps.map((s) => ({ ...s })) : [],
        };
        this.showTemplates = false;
    },

    get edProblems() {
        return templateProblems(this.ed.steps);
    },

    get edSequence() {
        // Preview from the first team's point of view.
        return buildSequence({ steps: this.ed.steps }, true);
    },

    edAdd(action, team = null) {
        const last = this.ed.steps[this.ed.steps.length - 1];
        const t = team ?? (last ? (last.team === 'first' ? 'second' : 'first') : 'first');
        this.ed.steps.push({ action, team: t, player: nextPlayer(this.ed.steps, action, t) });
    },

    edQuick(kind) {
        const s = this.ed.steps;
        const presets = {
            'snake-picks': () => addTurns(s, 'pick', 5, 'snake'),
            'bans-3-snake': () => addTurns(s, 'ban', 3, 'snake'),
            'bans-2-snake': () => addTurns(s, 'ban', 2, 'snake'),
            'bans-3-alt': () => addTurns(s, 'ban', 3, 'alternate'),
            'bans-2-alt-second': () => addTurns(s, 'ban', 2, 'alternate', 'second'),
            'picks-3-snake': () => addTurns(s, 'pick', 3, 'snake'),
            'picks-2-snake-second': () => addTurns(s, 'pick', 2, 'snake', 'second'),
        };
        this.ed.steps = presets[kind]?.() ?? s;
    },

    edMove(i, dir) {
        const j = i + dir;
        if (j < 0 || j >= this.ed.steps.length) return;
        const steps = [...this.ed.steps];
        [steps[i], steps[j]] = [steps[j], steps[i]];
        this.ed.steps = steps;
    },

    edRemove(i) {
        this.ed.steps = this.ed.steps.filter((_, k) => k !== i);
    },

    async saveTemplate() {
        if (this.edProblems.length || this.ed.saving) return;
        this.ed.saving = true;
        this.ed.error = null;
        store.set('author', this.ed.author);

        const body = { name: this.ed.name, description: this.ed.description, author: this.ed.author, blindBans: this.ed.blindBans, steps: this.ed.steps };
        try {
            if (this.ed.id) {
                const data = await send('PUT', `/mlbb-draft/templates/${this.ed.id}`, body, { 'X-Template-Key': this.myKeys[this.ed.id] });
                this.templates = this.templates.map((t) => (t.id === data.template.id ? data.template : t));
                // Re-apply if the edited template is the one in use.
                if (this.d.templateId === data.template.id) {
                    this.d.templateId = null;
                    this.applyTemplate();
                }
            } else {
                const data = await send('POST', '/mlbb-draft/templates', body);
                this.myKeys = { ...this.myKeys, [data.template.id]: data.key };
                store.set('templateKeys', this.myKeys);
                this.templates = [...this.builtInTemplates, data.template, ...this.communityTemplates];
                this.templateChoice = data.template.id;
            }
            this.ed.open = false;
        } catch (e) {
            this.ed.error = e.message;
        } finally {
            this.ed.saving = false;
        }
    },

    async deleteTemplate(t) {
        if (!this.canEdit(t) || !confirm(`Delete “${t.name}” for everyone?`)) return;
        try {
            await send('DELETE', `/mlbb-draft/templates/${t.id}`, null, { 'X-Template-Key': this.myKeys[t.id] });
            this.templates = this.templates.filter((x) => x.id !== t.id);
            const { [t.id]: _gone, ...rest } = this.myKeys;
            this.myKeys = rest;
            store.set('templateKeys', rest);
            if (this.templateChoice === t.id) this.templateChoice = 'auto';
            this.ed.open = false;
        } catch (e) {
            alert(e.message);
        }
    },

    // ------------------------------------------------------------ draft state

    get allyIds() {
        return this.d.picks.ally.map((p) => p.id).filter(Boolean);
    },

    get enemyIds() {
        return this.d.picks.enemy.map((p) => p.id).filter(Boolean);
    },

    get bannedIds() {
        return [...this.d.bans.ally, ...this.d.bans.enemy].filter(Boolean);
    },

    get unavailable() {
        return new Set([...this.bannedIds, ...this.allyIds, ...this.enemyIds]);
    },

    get phase() {
        return this.d.active?.kind ?? 'done';
    },

    filled(step) {
        return step.kind === 'ban' ? !!this.d.bans[step.team][step.index] : !!this.d.picks[step.team][step.index].id;
    },

    stepOf(kind, team, index) {
        return this.seq.find((s) => s.kind === kind && s.team === team && s.index === index) ?? null;
    },

    /** Ban slots a team uses in this template, grouped by ban round. */
    banRounds(team) {
        const rounds = new Map();
        for (const s of this.seq) {
            if (s.kind !== 'ban' || s.team !== team) continue;
            if (!rounds.has(s.block)) rounds.set(s.block, []);
            rounds.get(s.block).push(s.index);
        }
        return [...rounds.entries()].map(([block, slots]) => ({ block, slots }));
    },

    get hasBans() {
        return this.seq.some((s) => s.kind === 'ban');
    },

    /** Move to the next unfilled turn of the template. */
    advance() {
        const next = this.seq.find((s) => !this.filled(s) && !(s.kind === 'ban' && this.d.bansSkipped));
        this.d.active = next ? { kind: next.kind, team: next.team, index: next.index } : null;
    },

    /** Jump to the advisor tab that fits the phase (bans ↔ picks). */
    autoTab() {
        if (this.d.active?.kind === 'ban') this.tab = 'bans';
        else if (this.tab === 'bans') this.tab = 'pick';
    },

    /** Let the user say which team is banning right now (overrides the template order). */
    banAs(team) {
        const next = this.seq.find((s) => s.kind === 'ban' && s.team === team && !this.filled(s));
        if (next) {
            this.d.bansSkipped = false;
            this.d.active = { kind: 'ban', team, index: next.index };
            this.save();
        }
    },

    bansLeft(team) {
        return this.seq.filter((s) => s.kind === 'ban' && s.team === team && !this.filled(s)).length;
    },

    skipBans() {
        this.d.bansSkipped = true;
        this.advance();
        this.autoTab();
        this.save();
    },

    focus(kind, team, index) {
        if (kind === 'ban') this.d.bansSkipped = false; // going back to a ban re-opens bans
        this.d.active = { kind, team, index };
        this.save();
    },

    isActive(kind, team, index) {
        const a = this.d.active;
        return a && a.kind === kind && a.team === team && a.index === index;
    },

    /**
     * Can `id` go into the active slot? With blind bans a team may ban a hero the other
     * team banned in the SAME ban round (both banned it blind). Earlier rounds are revealed,
     * so their bans can't be repeated.
     */
    canChoose(id) {
        const a = this.d.active;
        if (!a || !this.heroes.has(id)) return false;
        if (this.allyIds.includes(id) || this.enemyIds.includes(id)) return false;

        if (a.kind === 'pick') return !this.bannedIds.includes(id);

        if (this.d.bans[a.team].includes(id)) return false;
        const other = a.team === 'ally' ? 'enemy' : 'ally';
        const j = this.d.bans[other].indexOf(id);
        if (j < 0) return true;
        return !!this.template.blindBans && this.stepOf('ban', other, j)?.block === this.stepOf('ban', a.team, a.index)?.block;
    },

    /** Every banned hero once, in ban order, with the team(s) that banned it. */
    get bannedList() {
        const list = new Map();
        for (const s of this.seq) {
            const id = s.kind === 'ban' ? this.d.bans[s.team][s.index] : null;
            if (!id) continue;
            if (!list.has(id)) list.set(id, { id, by: [] });
            list.get(id).by.push(s.team);
        }
        return [...list.values()];
    },

    get totalBans() {
        return this.seq.filter((s) => s.kind === 'ban').length;
    },

    bannedTitle(entry) {
        const who = entry.by.length > 1 ? 'Banned by both teams' : entry.by[0] === 'ally' ? 'Banned by our team' : 'Banned by the enemy';
        const a = this.d.active;
        if (a?.kind !== 'ban') return `${this.hero(entry.id)?.name} · ${who}`;
        if (this.canChoose(entry.id)) return `${this.hero(entry.id)?.name} · ${who} — tap to ban it for ${a.team === 'ally' ? 'our team' : 'the enemy'} too`;
        return `${this.hero(entry.id)?.name} · ${who}${this.template.blindBans ? ' (only bans from the current round can be repeated)' : ' (open bans: can’t be banned twice)'}`;
    },

    choose(id) {
        const a = this.d.active;
        if (!this.canChoose(id)) return;

        this.history.push(JSON.stringify(this.d));
        if (a.kind === 'ban') {
            this.d.bans[a.team][a.index] = id;
        } else {
            // Keep the lane the player declared before the draft.
            this.d.picks[a.team][a.index] = { id, lane: this.d.picks[a.team][a.index].lane };
        }
        this.q = '';
        this.advance();
        this.autoTab();
        this.save();
        this.loadMatchups();
    },

    clearSlot(kind, team, index) {
        this.history.push(JSON.stringify(this.d));
        if (kind === 'ban') this.d.bans[team][index] = null;
        else this.d.picks[team][index] = { id: null, lane: this.d.picks[team][index].lane };
        this.d.active = { kind, team, index };
        this.save();
    },

    setLane(team, index, lane) {
        this.d.picks[team][index].lane = lane || null;
        this.save();
    },

    undo() {
        const prev = this.history.pop();
        if (prev) {
            this.d = JSON.parse(prev);
            this.save();
        }
    },

    reset() {
        this.history.push(JSON.stringify(this.d));
        this.d = emptyDraft(this.template.id);
        this.advance();
        this.tab = this.d.active?.kind === 'ban' ? 'bans' : 'pick';
        this.save();
    },

    save() {
        store.set('draft', this.d);
    },

    // --------------------------------------------------------------- advisor

    get ctx() {
        return {
            heroes: this.heroes,
            stats: this.stats,
            norm: this.norm,
            matchups: this.matchups,
            unavailable: this.unavailable,
            allyIds: this.allyIds,
            enemyIds: this.enemyIds,
            allyPicks: this.d.picks.ally,
            enemyPicks: this.d.picks.enemy,
        };
    },

    /** The ally pick we're advising for: the active one if it's ours, else our next empty one. */
    get adviseSlot() {
        const a = this.d.active;
        if (a?.kind === 'pick' && a.team === 'ally') return a.index;
        return this.seq.find((s) => s.kind === 'pick' && s.team === 'ally' && !this.filled(s))?.index ?? null;
    },

    /** Recomputed once per change (see init), instead of on every template read. */
    recompute() {
        if (!this.heroes.size) {
            this.advice = { pick: [], byLane: [], enemy: [], enemyNeeds: [], bans: [], check: [], rank: new Map(), scores: new Map() };
            return;
        }

        const ctx = this.ctx;
        const slot = this.adviseSlot;
        const picks = this.seq.filter((s) => s.kind === 'pick');
        const allyPicks = picks.filter((s) => s.team === 'ally');
        const opts = {
            slot,
            pool: this.pool,
            firstPick: picks[0]?.team === 'ally' && this.allyIds.length === 0,
            lastPick: allyPicks[allyPicks.length - 1]?.index === slot,
        };

        // Best options for every lane our team still needs — counters, synergy, win/pick/ban rate.
        const pick = slot === null ? [] : recommendPicks(ctx, opts);
        const byLane = slot === null ? [] : recommendByLane(ctx, opts, 3);

        // The "enemy may ban it too" discount only matters on our own blind ban turns.
        const ourBanTurn = this.phase === 'ban' && this.d.active.team === 'ally';
        const bans = recommendBans(ctx, this.pool, { blind: ourBanTurn && !!this.template.blindBans });
        const current = this.phase === 'ban' ? bans : pick;

        this.advice = {
            pick: pick.slice(0, 6),
            byLane,
            enemy: predictEnemy(ctx).slice(0, 6),
            enemyNeeds: openLanes(this.d.picks.enemy, this.heroes),
            bans: bans.slice(0, 8),
            check: teamCheck(this.d.picks.ally, this.d.picks.enemy, this.heroes),
            rank: new Map(current.slice(0, 5).map((r, i) => [r.id, i + 1])),
            scores: new Map(current.map((r) => [r.id, r.score])),
        };
    },

    get waveNote() {
        const a = this.d.active;
        if (a?.kind !== 'ban') return '';
        const step = this.stepOf('ban', a.team, a.index);
        const rounds = new Set(this.seq.filter((s) => s.kind === 'ban').map((s) => s.block)).size;
        const round = rounds > 1 ? `Ban round ${step?.block} of ${rounds}. ` : '';
        return round + (this.template.blindBans
            ? 'Bans are blind here, so both teams may ban the same hero. Use “Banning now” if bans happen in a different order.'
            : 'Bans are open: a hero banned by either team is gone. Use “Banning now” if bans happen in a different order.');
    },

    isDupBan(team, index) {
        const id = this.d.bans[team][index];
        const other = team === 'ally' ? 'enemy' : 'ally';
        return !!id && this.d.bans[other].includes(id);
    },

    lanesFor(team) {
        return assignLanes(this.d.picks[team], this.heroes);
    },

    pickTurn(team, index) {
        return this.stepOf('pick', team, index)?.turn ?? '';
    },

    turnLabel() {
        const a = this.d.active;
        if (!a) return 'Draft complete';
        const step = this.stepOf(a.kind, a.team, a.index);
        const who = `${a.team === 'ally' ? 'Our' : 'Enemy'} player ${a.index + 1}`;
        if (a.kind === 'ban') {
            const total = this.seq.filter((s) => s.kind === 'ban').length;
            return `Ban ${step?.turn ?? ''}/${total} · ${who}`;
        }
        return `Pick ${step?.turn ?? ''} · ${who}`;
    },

    // ------------------------------------------------------------ hero grid

    get grid() {
        const q = this.q.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
        let list = this.heroList.filter((h) => {
            if (q && !h.name.toLowerCase().replace(/[^a-z0-9]/g, '').includes(q)) return false;
            if (this.roleFilter && !h.roles.includes(this.roleFilter)) return false;
            if (this.laneFilter && !h.lanes.includes(this.laneFilter)) return false;
            return true;
        });

        const s = (h) => this.stats[h.id] ?? { wr: 0, pr: 0, br: 0 };
        if (this.sort === 'wr') list = [...list].sort((a, b) => s(b).wr - s(a).wr);
        if (this.sort === 'br') list = [...list].sort((a, b) => s(b).br - s(a).br);
        if (this.sort === 'pr') list = [...list].sort((a, b) => s(b).pr - s(a).pr);
        if (this.sort === 'smart') {
            const scores = this.advice.scores;
            list = [...list].sort((a, b) => this.unavailable.has(a.id) - this.unavailable.has(b.id) || (scores.get(b.id) ?? -99) - (scores.get(a.id) ?? -99));
        }
        return list;
    },

    pickFirstResult() {
        const hit = this.grid.find((h) => this.canChoose(h.id));
        if (hit) this.choose(hit.id);
    },

    tileState(id) {
        if (this.d.bans.ally.includes(id) || this.d.bans.enemy.includes(id)) return 'banned';
        if (this.allyIds.includes(id)) return 'ally';
        if (this.enemyIds.includes(id)) return 'enemy';
        return '';
    },

    hero(id) {
        return this.heroes.get(id);
    },

    img(id, size = 96) {
        return portrait(this.heroes.get(id)?.head, size);
    },

    wr(id) {
        const s = this.stats[id];
        return s ? (s.wr * 100).toFixed(1) + '%' : '—';
    },

    br(id) {
        const s = this.stats[id];
        return s ? Math.round(s.br * 100) + '%' : '—';
    },

    wrClass(id) {
        const wr = this.stats[id]?.wr ?? 0.5;
        return wr >= 0.53 ? 'hot' : wr >= 0.505 ? 'warm' : wr < 0.47 ? 'cold' : '';
    },

    updatedLabel() {
        if (!this.updatedAt) return '';
        const d = new Date(this.updatedAt);
        return d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    },

    // ------------------------------------------------------------------ pool

    togglePool(id) {
        this.pool = this.pool.includes(id) ? this.pool.filter((x) => x !== id) : [...this.pool, id].slice(0, 15);
    },

    get poolResults() {
        const q = this.poolQuery.trim().toLowerCase();
        return this.heroList.filter((h) => !q || h.name.toLowerCase().includes(q)).slice(0, 60);
    },
}));

window.Alpine = Alpine;
Alpine.start();
