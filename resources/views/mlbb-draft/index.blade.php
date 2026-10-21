<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0f1f">
    <meta name="description" content="Draft your Mobile Legends ranked games: bans, counter picks and synergy for your rank, updated daily.">
    <title>MLBB Draft · Ranked draft advisor</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cpath d='M16 2 29 9v14L16 30 3 23V9z' fill='%23e8b64c'/%3E%3Cpath d='m10 21 6-11 6 11' fill='none' stroke='%230a0f1f' stroke-width='3'/%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script type="application/json" id="mlbb-config">@json($config)</script>
    @vite(['resources/css/mlbb-draft.css', 'resources/js/mlbb-draft/main.js'])
</head>
<body x-data="draft">

<header class="topbar">
    <div class="brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="brand-name">MLBB <b>Draft</b></span>
    </div>

    <div class="top-controls">
        <label class="ctl">
            <span>Rank</span>
            <select x-model="rankKey" aria-label="Your rank">
                <template x-for="r in ranks" :key="r.key"><option :value="r.key" x-text="r.label" :selected="r.key === rankKey"></option></template>
            </select>
        </label>
        <label class="ctl">
            <span>Stats from</span>
            <select x-model.number="days" aria-label="Stats window">
                <template x-for="w in windows" :key="w"><option :value="w" x-text="w === 1 ? 'Last 24 hours' : 'Last ' + w + ' days'" :selected="w === days"></option></template>
            </select>
        </label>
        <span class="data-badge" :class="error && 'err'" :title="updatedAt ? 'Stats refresh automatically every day' : ''">
            <i class="dot"></i>
            <span x-show="loading">Loading hero stats…</span>
            <span x-show="!loading && !error" x-text="rank.label + ' stats · updated ' + updatedLabel()"></span>
            <span x-show="!loading && error" x-text="error"></span>
        </span>
    </div>
</header>

{{-- Setup --}}
<section class="setup">
    <div class="setup-group">
        <span class="setup-label">Our team bans &amp; picks</span>
        <div class="seg">
            <button type="button" :class="weFirst && 'on'" @click="weFirst = true">First</button>
            <button type="button" :class="!weFirst && 'on'" @click="weFirst = false">Second</button>
        </div>
    </div>

    <div class="setup-group format-group">
        <span class="setup-label">Draft format</span>
        <div class="format-row">
            <select x-model="templateChoice" aria-label="Pick & ban template">
                <option value="auto" :selected="templateChoice === 'auto'" x-text="'Auto · ' + (templates.find(t => t.id === rank.template)?.name ?? 'by rank')"></option>
                <optgroup label="Built-in">
                    <template x-for="t in builtInTemplates" :key="t.id"><option :value="t.id" x-text="t.name" :selected="templateChoice === t.id"></option></template>
                </optgroup>
                <optgroup label="Community" x-show="communityTemplates.length">
                    <template x-for="t in communityTemplates" :key="t.id"><option :value="t.id" x-text="t.name + (t.author ? ' · ' + t.author : '')" :selected="templateChoice === t.id"></option></template>
                </optgroup>
            </select>
            <button type="button" class="btn" @click="showTemplates = true; refreshTemplates()">Templates</button>
        </div>
    </div>

    <div class="setup-group pool-group">
        <span class="setup-label">Team pool</span>
        <button type="button" class="pool-btn" @click="showPool = true">
            <template x-for="id in pool.slice(0, 6)" :key="id"><img :src="img(id, 48)" :alt="hero(id)?.name" loading="lazy"></template>
            <span x-text="pool.length ? (pool.length > 6 ? '+' + (pool.length - 6) : 'Edit') : 'Add heroes your team plays well'"></span>
        </button>
    </div>

    <div class="setup-actions">
        <button type="button" class="btn" @click="undo()" :disabled="!history.length">Undo</button>
        <button type="button" class="btn btn-danger" @click="reset()">New draft</button>
    </div>
</section>

<main class="board">
    {{-- ========================================================= our team --}}
    <aside class="team team-ally">
        <h2 class="team-head"><span>Our team</span><small x-text="weFirst ? 'Bans & picks first' : 'Bans & picks second'"></small></h2>

        <div class="bans" x-show="hasBans">
            <template x-for="round in banRounds('ally')" :key="'abw' + round.block">
                <div class="ban-wave">
                    <span class="wave-label" x-text="banRounds('ally').length > 1 ? 'Round ' + round.block : 'Bans'"></span>
                    <div class="wave-slots">
                        <template x-for="i in round.slots" :key="'ab' + i">
                            <button type="button" class="ban-slot" :class="[isActive('ban', 'ally', i) && 'active', d.bans.ally[i] && 'filled', isDupBan('ally', i) && 'dup']" @click="d.bans.ally[i] ? clearSlot('ban', 'ally', i) : focus('ban', 'ally', i)" :title="(d.bans.ally[i] ? 'Clear ban: ' + hero(d.bans.ally[i])?.name : 'Player ' + (i + 1) + ' ban') + (isDupBan('ally', i) ? ' (both teams banned it)' : '')">
                                <template x-if="d.bans.ally[i]"><img :src="img(d.bans.ally[i], 64)" :alt="hero(d.bans.ally[i])?.name"></template>
                                <span class="ban-player" x-text="'P' + (i + 1)"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <ol class="picks">
            <template x-for="(p, i) in d.picks.ally" :key="'ap' + i">
                <li class="pick" :class="[isActive('pick', 'ally', i) && 'active', p.id && 'filled']">
                    <button type="button" class="pick-face" @click="p.id ? clearSlot('pick', 'ally', i) : focus('pick', 'ally', i)" :title="p.id ? 'Clear' : 'Pick slot'">
                        <template x-if="p.id"><img :src="img(p.id, 112)" :alt="hero(p.id)?.name"></template>
                        <span class="pick-no" x-text="'#' + pickTurn('ally', i)"></span>
                    </button>
                    <div class="pick-info">
                        <span class="pick-name" x-text="p.id ? hero(p.id)?.name : 'Player ' + (i + 1)"></span>
                        <template x-if="p.id">
                            <select class="lane-select" @change="setLane('ally', i, $event.target.value)" aria-label="Lane" title="Lane this hero plays (change it if they swap)">
                                <template x-for="l in lanes" :key="l"><option :value="l" x-text="laneLabel[l]" :selected="l === (p.lane || lanesFor('ally')[i])"></option></template>
                            </select>
                        </template>
                    </div>
                </li>
            </template>
        </ol>

        <ul class="checks">
            <template x-for="(n, i) in advice.check" :key="i"><li :class="'check-' + n.level" x-text="n.text"></li></template>
        </ul>
    </aside>

    {{-- =========================================================== centre --}}
    <section class="centre">
        <div class="turn" :class="d.active ? 'turn-' + d.active.team : 'turn-done'">
            <span class="turn-label" x-text="turnLabel()"></span>
            <div class="turn-actions" x-show="phase === 'ban'">
                <span class="turn-hint">Banning now:</span>
                <div class="seg seg-sm">
                    <button type="button" :class="d.active?.team === 'ally' && 'on'" :disabled="!bansLeft('ally')" @click="banAs('ally')">Our team <small x-text="bansLeft('ally')"></small></button>
                    <button type="button" :class="d.active?.team === 'enemy' && 'on'" :disabled="!bansLeft('enemy')" @click="banAs('enemy')">Enemy <small x-text="bansLeft('enemy')"></small></button>
                </div>
                <button type="button" class="link" @click="skipBans()">Skip bans →</button>
            </div>
        </div>

        {{-- Banned heroes: always visible; tap one to ban it for this team too (blind bans) --}}
        <div class="banned-bar" x-show="hasBans">
            <div class="banned-head">
                <span class="banned-title">Banned <small x-text="bannedIds.length + '/' + totalBans"></small></span>
                <span class="banned-hint" x-show="phase === 'ban' && template.blindBans && bannedList.length">Blind bans: tap a banned hero if <b x-text="d.active?.team === 'ally' ? 'our team' : 'the enemy'"></b> banned it too.</span>
            </div>
            <ol class="banned-list">
                <template x-for="b in bannedList" :key="b.id">
                    <li>
                        <button type="button" class="banned-hero" :class="[b.by.length > 1 && 'both', canChoose(b.id) && 'can-ban']" :disabled="!canChoose(b.id)" @click="choose(b.id)" :title="bannedTitle(b)">
                            <img :src="img(b.id, 64)" :alt="hero(b.id)?.name">
                            <span class="banned-name" x-text="hero(b.id)?.name"></span>
                            <span class="banned-by">
                                <template x-for="t in b.by" :key="t"><i :class="'by-' + t" :title="t === 'ally' ? 'Our team' : 'Enemy'"></i></template>
                            </span>
                            <span class="banned-dup" x-show="b.by.length > 1">×2</span>
                        </button>
                    </li>
                </template>
                <li class="banned-empty" x-show="!bannedList.length">No bans yet.</li>
            </ol>
        </div>

        {{-- Advisor --}}
        <div class="advisor">
            <div class="adv-tabs" role="tablist">
                <button type="button" role="tab" :class="tab === 'pick' && 'on'" @click="tab = 'pick'">Our next pick · by lane</button>
                <button type="button" role="tab" :class="tab === 'enemy' && 'on'" @click="tab = 'enemy'">Enemy may pick</button>
                <button type="button" role="tab" :class="tab === 'bans' && 'on'" @click="tab = 'bans'">Ban these</button>
            </div>

            <div class="adv-body">
                <template x-if="loading"><p class="muted">Loading hero data…</p></template>

                <template x-if="!loading && tab === 'pick'">
                    <div>
                        <p class="adv-hint">
                            <template x-if="advice.byLane.length">
                                <span>Lanes we still need: <b x-text="advice.byLane.map(c => laneLabel[c.lane]).join(', ')"></b>. Each hero is ranked by counters vs the enemy picks, synergy with ours, win rate at <b x-text="rank.label"></b> and how contested it is (pick/ban rate).</span>
                            </template>
                            <template x-if="!advice.byLane.length"><span>All our picks are in.</span></template>
                        </p>
                        @include('mlbb-draft.partials.lane-columns')
                    </div>
                </template>

                <template x-if="!loading && tab === 'enemy'">
                    <div>
                        <p class="adv-hint">Most likely enemy picks from what's still open: ban/pick rates at this rank, their missing lanes<template x-if="enemyIds.length && advice.enemyNeeds.length"><span> (<b x-text="advice.enemyNeeds.map(l => laneLabel[l]).join(', ')"></b>)</span></template>, and heroes that counter our picks.</p>
                        @include('mlbb-draft.partials.advice-list', ['list' => 'advice.enemy', 'mode' => 'enemy'])
                    </div>
                </template>

                <template x-if="!loading && tab === 'bans'">
                    <div>
                        <p class="adv-hint wave-note" x-show="waveNote" x-text="waveNote"></p>
                        <p class="adv-hint">Biggest threats still open. Heroes in the team pool are never suggested, and heroes that counter them (or our picks) rank higher.</p>
                        @include('mlbb-draft.partials.advice-list', ['list' => 'advice.bans', 'mode' => 'ban'])

                        <template x-if="advice.byLane.length">
                            <div class="still-open">
                                <h4>Best picks still open, by lane <small>updates after every ban</small></h4>
                                <ol class="mini-list">
                                    <template x-for="col in advice.byLane" :key="col.lane">
                                        <template x-if="col.picks[0]">
                                            <li :title="col.picks[0].reasons.slice(0, 3).map(x => x.text).join(' · ')">
                                                <span class="lane-chip" x-text="laneLabel[col.lane]"></span>
                                                <img :src="img(col.picks[0].id, 64)" :alt="hero(col.picks[0].id)?.name">
                                                <span x-text="hero(col.picks[0].id)?.name"></span>
                                                <b :class="wrClass(col.picks[0].id)" x-text="wr(col.picks[0].id)"></b>
                                            </li>
                                        </template>
                                    </template>
                                </ol>
                            </div>
                        </template>
                    </div>
                </template>

            </div>
        </div>

        {{-- Hero grid --}}
        <div class="grid-tools">
            <input type="search" x-model="q" @keydown.enter.prevent="pickFirstResult()" placeholder="Search hero… (Enter to lock in)" aria-label="Search heroes" autocomplete="off">
            <select x-model="roleFilter" aria-label="Role">
                <option value="">All roles</option>
                <template x-for="r in roles" :key="r"><option :value="r" x-text="r.charAt(0).toUpperCase() + r.slice(1)"></option></template>
            </select>
            <select x-model="laneFilter" aria-label="Lane">
                <option value="">All lanes</option>
                <template x-for="l in lanes" :key="l"><option :value="l" x-text="laneLabel[l]"></option></template>
            </select>
            <select x-model="sort" aria-label="Sort">
                <option value="smart">Recommended</option>
                <option value="wr">Win rate</option>
                <option value="br">Ban rate</option>
                <option value="pr">Pick rate</option>
            </select>
        </div>

        <div class="hero-grid" x-show="!loading">
            <template x-for="h in grid" :key="h.id">
                <button type="button" class="tile" :class="['state-' + tileState(h.id), advice.rank.get(h.id) && 'rec', tileState(h.id) === 'banned' && canChoose(h.id) && 'dup-ok']" :disabled="!canChoose(h.id)" @click="choose(h.id)" :title="h.name + ' · ' + h.roles.join('/') + ' · ' + h.lanes.map(l => laneLabel[l]).join('/')">
                    <img :src="img(h.id, 96)" :alt="h.name" loading="lazy">
                    <span class="tile-rank" x-show="advice.rank.get(h.id)" x-text="advice.rank.get(h.id)"></span>
                    <span class="tile-name" x-text="h.name"></span>
                    <span class="tile-stats"><b :class="wrClass(h.id)" x-text="wr(h.id)"></b><i x-text="'ban ' + br(h.id)"></i></span>
                </button>
            </template>
        </div>
    </section>

    {{-- =========================================================== enemy --}}
    <aside class="team team-enemy">
        <h2 class="team-head"><span>Enemy</span><small x-text="weFirst ? 'Bans & picks second' : 'Bans & picks first'"></small></h2>

        <div class="bans" x-show="hasBans">
            <template x-for="round in banRounds('enemy')" :key="'ebw' + round.block">
                <div class="ban-wave">
                    <span class="wave-label" x-text="banRounds('enemy').length > 1 ? 'Round ' + round.block : 'Bans'"></span>
                    <div class="wave-slots">
                        <template x-for="i in round.slots" :key="'eb' + i">
                            <button type="button" class="ban-slot" :class="[isActive('ban', 'enemy', i) && 'active', d.bans.enemy[i] && 'filled', false, isDupBan('enemy', i) && 'dup']" @click="d.bans.enemy[i] ? clearSlot('ban', 'enemy', i) : focus('ban', 'enemy', i)" :title="(d.bans.enemy[i] ? 'Clear ban: ' + hero(d.bans.enemy[i])?.name : 'Player ' + (i + 1) + ' ban') + (isDupBan('enemy', i) ? ' (both teams banned it)' : '')">
                                <template x-if="d.bans.enemy[i]"><img :src="img(d.bans.enemy[i], 64)" :alt="hero(d.bans.enemy[i])?.name"></template>
                                <span class="ban-player" x-text="'P' + (i + 1)"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <ol class="picks">
            <template x-for="(p, i) in d.picks.enemy" :key="'ep' + i">
                <li class="pick" :class="[isActive('pick', 'enemy', i) && 'active', p.id && 'filled']">
                    <button type="button" class="pick-face" @click="p.id ? clearSlot('pick', 'enemy', i) : focus('pick', 'enemy', i)" :title="p.id ? 'Clear' : 'Pick slot'">
                        <template x-if="p.id"><img :src="img(p.id, 112)" :alt="hero(p.id)?.name"></template>
                        <span class="pick-no" x-text="'#' + pickTurn('enemy', i)"></span>
                    </button>
                    <div class="pick-info">
                        <span class="pick-name" x-text="p.id ? hero(p.id)?.name : 'Enemy ' + (i + 1)"></span>
                        <template x-if="p.id">
                            <select class="lane-select" @change="setLane('enemy', i, $event.target.value)" aria-label="Lane">
                                <template x-for="l in lanes" :key="l"><option :value="l" x-text="laneLabel[l]" :selected="l === (p.lane || lanesFor('enemy')[i])"></option></template>
                            </select>
                        </template>
                    </div>
                </li>
            </template>
        </ol>

        <details class="tips" open>
            <summary>Draft tips · <span x-text="rank.label"></span></summary>
            <ul>
                <template x-if="rank.bans === 0"><li>No bans below Epic — pick heroes the team is comfortable with that win lanes alone. The advisor still counter-picks the enemy.</li></template>
                <template x-if="rank.bans > 0 && rank.bans < 5"><li>With <span x-text="rank.bans"></span> bans each, spend them on the heroes the advisor flags as overperforming or as counters to your pool.</li></template>
                <template x-if="rank.bans === 5"><li>Five bans each removes most meta threats — counter-picks and synergy matter more than raw win rate here.</li></template>
                <template x-if="template.blindBans"><li>Bans are blind, so both teams often ban the same top hero. Agree on bans in chat first.</li></template>
                <template x-if="template.id === 'tournament'"><li>MPL format: after the first 3 picks each, there are 2 more bans per team. Use them on heroes that counter what your team already picked, or on the last hero the enemy needs.</li></template>
                <li>First pick: take a safe, flexible hero that is hard to counter. Save your hard counter-picks for your last pick.</li>
                <li>A hero a player has mastered usually beats a higher-tier hero they are still learning — add those to the <b>Team pool</b>.</li>
                <li>Season 42, patch 2.2.16a (14 Oct 2026): Eudora is still one of the most-banned heroes, and sustain picks (Floryn, Estes, Rafaela) mean someone should carry anti-heal.</li>
            </ul>
        </details>
    </aside>
</main>

<footer class="foot">
    Hero stats from <a href="https://arena.rone.dev" target="_blank" rel="noopener">Rone Arena</a> for the selected rank, refreshed daily. Counters and synergy are win-rate differences in ranked games — use them as a guide, not a rule.
</footer>

{{-- Hero pool picker --}}
<div class="modal" x-show="showPool" x-transition.opacity x-cloak @keydown.escape.window="showPool = false" @click.self="showPool = false">
    <div class="modal-card" role="dialog" aria-label="Team pool">
        <div class="modal-head">
            <h3>Team pool <small x-text="pool.length + '/15'"></small></h3>
            <button type="button" class="btn" @click="showPool = false">Done</button>
        </div>
        <p class="muted">Heroes your players are comfortable on. They get a boost in pick suggestions and are never suggested as bans.</p>
        <input type="search" x-model="poolQuery" placeholder="Search…" aria-label="Search heroes">
        <div class="pool-grid">
            <template x-for="h in poolResults" :key="h.id">
                <button type="button" class="pool-tile" :class="pool.includes(h.id) && 'on'" @click="togglePool(h.id)">
                    <img :src="img(h.id, 72)" :alt="h.name" loading="lazy">
                    <span x-text="h.name"></span>
                </button>
            </template>
        </div>
    </div>
</div>

{{-- Template library --}}
@include('mlbb-draft.partials.templates')

</body>
</html>
