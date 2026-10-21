import { AudioEngine, Music } from './audio.js';
import { SONGS, buildChart } from './chart.js';

const $ = (s) => document.querySelector(s);

const LANE_COLORS = ['#5eead4', '#fbbf24', '#fb7185', '#a78bfa'];
const LANE_KEYS = ['D', 'F', 'J', 'K'];
const KEYMAP = { KeyD: 0, KeyF: 1, KeyJ: 2, KeyK: 3, ArrowLeft: 0, ArrowDown: 1, ArrowUp: 2, ArrowRight: 3 };
const WINDOW = { perfect: 0.05, great: 0.09, good: 0.135 };
const POINTS = { perfect: 300, great: 200, good: 100, miss: 0 };
const HEALTH = { perfect: 2, great: 1.5, good: 0.5, miss: -8 };
const GRADES = [
    [0.95, 'S'],
    [0.88, 'A'],
    [0.78, 'B'],
    [0.65, 'C'],
    [0, 'D'],
];

// ------------------------------------------------------------------ storage

const store = {
    get(key, fallback) {
        try {
            const v = localStorage.getItem('triple-t:' + key);
            return v === null ? fallback : JSON.parse(v);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem('triple-t:' + key, JSON.stringify(value));
        } catch {
            // Private mode etc. — settings just won't persist.
        }
    },
};

const settings = {
    voice: store.get('voice', true),
    speed: store.get('speed', 1),
    offset: store.get('offset', 0),
};

// -------------------------------------------------------------------- state

const audio = new AudioEngine();
const canvas = $('#highway');
const g = canvas.getContext('2d');
const charEl = $('#tung');
const bubbleEl = $('#bubble');

let state = 'title'; // title | playing | paused | results
let game = null;
let view = { w: 0, h: 0, dpr: 1 };
let particles = [];
const laneGlow = [0, 0, 0, 0];
const held = new Set();
const pose = { kick: 0, jump: 0, shake: 0, kickDir: 1 };
let lastFrame = performance.now();

// --------------------------------------------------------------- the scenery

function buildSky() {
    const stars = $('#stars');
    for (let i = 0; i < 90; i++) {
        const s = document.createElement('span');
        s.style.left = Math.random() * 100 + '%';
        s.style.top = Math.random() * 62 + '%';
        s.style.setProperty('--d', (2 + Math.random() * 4).toFixed(2) + 's');
        s.style.animationDelay = (-Math.random() * 6).toFixed(2) + 's';
        if (Math.random() < 0.15) {
            s.className = 'big';
        }
        stars.appendChild(s);
    }

    // Kampung skyline: stilt houses with windows that light up as you wake them.
    const houses = [
        [20, 120, 70], [150, 100, 85], [290, 130, 60], [420, 110, 95],
        [740, 125, 80], [870, 100, 70], [1000, 135, 90], [1130, 105, 60],
    ];
    let svg = '';
    let wid = 0;
    for (const [x, w, h] of houses) {
        const base = 230;
        const top = base - 26 - h;
        svg += `<rect x="${x + 10}" y="${base - 26}" width="4" height="26"/><rect x="${x + w - 14}" y="${base - 26}" width="4" height="26"/>`;
        svg += `<rect x="${x + w / 2 - 2}" y="${base - 26}" width="4" height="26"/>`;
        svg += `<rect x="${x}" y="${top}" width="${w}" height="${h}"/>`;
        svg += `<path d="M${x - 12} ${top + 2} L${x + w / 2} ${top - 46} L${x + w + 12} ${top + 2} Z"/>`;
        const cols = w > 100 ? 3 : 2;
        for (let c = 0; c < cols; c++) {
            const wx = x + (w / (cols + 1)) * (c + 1) - 9;
            svg += `<rect class="win" data-win="${wid++}" x="${wx}" y="${top + h * 0.32}" width="18" height="22" rx="2"/>`;
        }
    }

    // Mosque in the middle — already awake, obviously
    svg += `<rect x="540" y="130" width="150" height="100"/><path d="M548 132 Q615 30 682 132 Z"/>`;
    svg += `<rect x="606" y="40" width="4" height="18"/><rect x="700" y="60" width="18" height="170"/><path d="M696 62 L709 26 L722 62 Z"/>`;
    svg += `<rect class="win lit always" x="572" y="160" width="16" height="34" rx="8"/><rect class="win lit always" x="607" y="160" width="16" height="34" rx="8"/><rect class="win lit always" x="642" y="160" width="16" height="34" rx="8"/>`;
    svg += `<rect x="0" y="228" width="1200" height="40"/>`;

    $('#kampung').innerHTML = svg;
    return wid;
}

const windowCount = buildSky();

function lightWindows(ratio) {
    const lit = Math.round(ratio * windowCount);
    document.querySelectorAll('#kampung .win[data-win]').forEach((w) => {
        w.classList.toggle('lit', Number(w.dataset.win) < lit);
    });
}

// ------------------------------------------------------------------- layout

function resize() {
    const r = canvas.getBoundingClientRect();
    view.dpr = Math.min(window.devicePixelRatio || 1, 2);
    view.w = r.width;
    view.h = r.height;
    canvas.width = Math.round(r.width * view.dpr);
    canvas.height = Math.round(r.height * view.dpr);
    g.setTransform(view.dpr, 0, 0, view.dpr, 0, 0);
}

window.addEventListener('resize', resize);
resize();

const hitY = () => view.h - 92;
const pxPerSec = () => Math.max(420, view.h * 0.82) * settings.speed;

// --------------------------------------------------------------------- game

function songNow() {
    return audio.now - game.songStart - audio.latency - settings.offset / 1000;
}

async function start(key) {
    audio.init();
    await audio.ctx.resume();
    audio.voice = settings.voice;

    game?.music?.stop();

    const chart = buildChart(key);
    game = {
        key,
        chart,
        notes: chart.notes.map((n) => ({ ...n, judged: null })),
        cursor: 0,
        score: 0,
        combo: 0,
        maxCombo: 0,
        health: 80,
        counts: { perfect: 0, great: 0, good: 0, miss: 0 },
        woken: 0,
        tungStreak: 0,
        songStart: audio.now + 0.35,
    };
    game.music = new Music(audio, chart);
    game.music.start(game.songStart);

    particles = [];
    lightWindows(0);
    updateHud();
    showScreen(null);
    state = 'playing';
    document.body.dataset.state = 'playing';

    audio.say('Tung tung tung tung tung tung tung tung tung sahur!', { rate: 1.25 });
}

function pause() {
    if (state !== 'playing') return;
    state = 'paused';
    audio.ctx.suspend();
    audio.hush();
    showScreen('#pause-screen');
}

function resume() {
    if (state !== 'paused') return;
    audio.ctx.resume();
    state = 'playing';
    showScreen(null);
}

function quitToTitle() {
    game?.music?.stop();
    audio.hush();
    audio.ctx?.resume();
    state = 'title';
    document.body.dataset.state = 'title';
    lightWindows(0);
    renderBests();
    showScreen('#title-screen');
}

function press(lane, both = false) {
    laneGlow[lane] = 1;
    if (both) laneGlow[1] = laneGlow[2] = 1;

    if (state !== 'playing') return;

    const now = songNow();
    let best = null;
    let bestDelta = Infinity;

    for (let i = game.cursor; i < game.notes.length; i++) {
        const n = game.notes[i];
        if (n.t - now > WINDOW.good) break;
        if (n.judged) continue;

        const fits = n.kind === 'sahur' ? both || lane === 1 || lane === 2 : !both && n.lane === lane;
        const delta = Math.abs(n.t - now);
        if (fits && delta <= WINDOW.good && delta < bestDelta) {
            best = n;
            bestDelta = delta;
        }
    }

    const t = audio.now;

    // Free drumming between notes is allowed — just quieter, and no penalty.
    if (!best) {
        audio.tung(t, both ? 1 : lane, 0.4);
        return;
    }

    const grade = bestDelta <= WINDOW.perfect ? 'perfect' : bestDelta <= WINDOW.great ? 'great' : 'good';
    if (best.kind === 'sahur') {
        audio.sahur(t);
        audio.say(best.finale ? 'Sahuuuur!' : 'Sahur!', { rate: 1.15 });
    } else {
        audio.tung(t, lane, 1);
    }
    judge(best, grade, now - best.t);
}

function judge(note, grade, delta = 0) {
    note.judged = grade;
    const c = game.counts;
    c[grade]++;

    const isSahur = note.kind === 'sahur';

    if (grade === 'miss') {
        game.combo = 0;
        game.tungStreak = 0;
        pose.shake = 1;
        say(isSahur ? 'zzz…' : '…tung?', 'sleepy');
    } else {
        game.combo++;
        game.maxCombo = Math.max(game.maxCombo, game.combo);
        const mult = multiplier();
        game.score += POINTS[grade] * mult * (isSahur ? 2 : 1);

        if (isSahur) {
            game.woken++;
            game.tungStreak = 0;
            pose.jump = 1;
            say('SAHUR!!!', 'sahur');
            lightWindows(game.woken / game.chart.sahurCount);
            burst(view.w / 2, hitY(), '#fde68a', 26, 1.4);
        } else {
            game.tungStreak++;
            pose.kick = 1;
            pose.kickDir *= -1;
            say('TUNG' + '!'.repeat(Math.min(game.tungStreak, 4)), 'tung');
            const laneW = view.w / 4;
            burst(laneW * (note.lane + 0.5), hitY(), LANE_COLORS[note.lane], 12, 1);
        }
    }

    game.health = Math.max(0, Math.min(100, game.health + HEALTH[grade] * (isSahur && grade === 'miss' ? 1.2 : 1)));

    flashJudgement(grade, delta);
    updateHud();

    if (game.health <= 0) {
        finish(true);
    }
}

const multiplier = () => Math.min(4, 1 + Math.floor(game.combo / 12));

function accuracy() {
    const c = game.counts;
    const judged = c.perfect + c.great + c.good + c.miss;
    if (!judged) return 1;
    return (c.perfect + c.great * 0.7 + c.good * 0.4) / judged;
}

function finish(failed) {
    if (state !== 'playing') return;
    state = 'results';
    document.body.dataset.state = 'results';
    game.music.stop();

    if (failed) {
        audio.hush();
        audio.fail();
    } else {
        audio.fanfare();
    }

    const acc = accuracy();
    const grade = failed ? 'F' : GRADES.find(([min]) => acc >= min)[1];

    const best = store.get('best:' + game.key, null);
    const isBest = !failed && (!best || game.score > best.score);
    if (isBest) {
        store.set('best:' + game.key, { score: game.score, grade });
    }

    const c = game.counts;
    $('#res-title').textContent = failed ? 'The kampung overslept' : grade === 'S' || grade === 'A' ? 'Everyone is awake!' : 'Sahur served';
    $('#res-sub').textContent = failed
        ? 'Too many missed tungs. The whole street slept through sahur.'
        : `${game.chart.label} · ${game.chart.bpm} BPM`;
    $('#res-grade').textContent = grade;
    $('#res-grade').dataset.grade = grade;
    $('#res-score').textContent = game.score.toLocaleString();
    $('#res-best').hidden = !isBest;
    $('#res-acc').textContent = (acc * 100).toFixed(1) + '%';
    $('#res-combo').textContent = game.maxCombo;
    $('#res-woken').textContent = `${game.woken} / ${game.chart.sahurCount}`;
    $('#res-perfect').textContent = c.perfect;
    $('#res-great').textContent = c.great;
    $('#res-good').textContent = c.good;
    $('#res-miss').textContent = c.miss;

    setTimeout(() => showScreen('#results-screen'), failed ? 900 : 600);
}

// ----------------------------------------------------------------------- UI

function showScreen(sel) {
    document.querySelectorAll('.screen').forEach((s) => (s.hidden = true));
    if (sel) {
        $(sel).hidden = false;
        $(sel).querySelector('[data-autofocus]')?.focus();
    }
}

function updateHud() {
    $('#hud-score').textContent = game.score.toLocaleString();
    $('#hud-mult').textContent = '×' + multiplier();
    $('#hud-acc').textContent = (accuracy() * 100).toFixed(1) + '%';
    $('#hud-woken').textContent = `${game.woken}/${game.chart.sahurCount}`;
    $('#hud-health').style.setProperty('--hp', game.health + '%');
    $('#hud-health').classList.toggle('danger', game.health < 25);

    const combo = $('#combo');
    combo.querySelector('b').textContent = game.combo;
    combo.classList.toggle('show', game.combo >= 4);
}

function flashJudgement(grade, delta) {
    const el = $('#judge');
    el.textContent = grade.toUpperCase();
    el.dataset.grade = grade;
    el.dataset.timing = grade === 'perfect' || grade === 'miss' ? '' : delta < 0 ? 'early' : 'late';
    el.classList.remove('pop');
    void el.offsetWidth;
    el.classList.add('pop');
}

function say(text, mood) {
    bubbleEl.textContent = text;
    bubbleEl.dataset.mood = mood;
    bubbleEl.classList.remove('pop');
    void bubbleEl.offsetWidth;
    bubbleEl.classList.add('pop');
}

function renderBests() {
    Object.keys(SONGS).forEach((key) => {
        const best = store.get('best:' + key, null);
        const el = document.querySelector(`[data-best="${key}"]`);
        if (el) el.textContent = best ? `Best ${best.score.toLocaleString()} · ${best.grade}` : 'Not played yet';
    });
}

function renderSettings() {
    $('#opt-voice').textContent = settings.voice ? 'On' : 'Off';
    $('#opt-voice').setAttribute('aria-pressed', settings.voice);
    $('#opt-speed').textContent = settings.speed.toFixed(2).replace(/0$/, '') + '×';
    $('#opt-offset').textContent = (settings.offset > 0 ? '+' : '') + settings.offset + ' ms';
}

// -------------------------------------------------------------------- input

window.addEventListener('keydown', (e) => {
    if (e.repeat) return;

    if (e.code === 'Escape' || e.code === 'KeyP') {
        if (state === 'playing') pause();
        else if (state === 'paused') resume();
        return;
    }

    if (e.code === 'Space') {
        if (state === 'playing') {
            e.preventDefault();
            held.add('space');
            press(1, true);
        }
        return;
    }

    const lane = KEYMAP[e.code];
    if (lane === undefined || e.metaKey || e.ctrlKey) return;
    if (state === 'playing') e.preventDefault();
    held.add(lane);
    press(lane);
});

window.addEventListener('keyup', (e) => {
    held.delete(e.code === 'Space' ? 'space' : KEYMAP[e.code]);
});

const pointers = new Map();
canvas.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    const r = canvas.getBoundingClientRect();
    const lane = Math.max(0, Math.min(3, Math.floor(((e.clientX - r.left) / r.width) * 4)));
    pointers.set(e.pointerId, lane);
    held.add(lane);
    press(lane);
});
const release = (e) => {
    held.delete(pointers.get(e.pointerId));
    pointers.delete(e.pointerId);
};
canvas.addEventListener('pointerup', release);
canvas.addEventListener('pointercancel', release);

document.addEventListener('visibilitychange', () => {
    if (document.hidden) pause();
});

document.querySelectorAll('[data-start]').forEach((b) => b.addEventListener('click', () => start(b.dataset.start)));
$('#btn-pause').addEventListener('click', pause);
$('#btn-resume').addEventListener('click', resume);
$('#btn-restart').addEventListener('click', () => start(game.key));
$('#btn-quit').addEventListener('click', quitToTitle);
$('#btn-retry').addEventListener('click', () => start(game.key));
$('#btn-menu').addEventListener('click', quitToTitle);

$('#opt-voice').addEventListener('click', () => {
    settings.voice = !settings.voice;
    store.set('voice', settings.voice);
    renderSettings();
});
$('#opt-speed').addEventListener('click', () => {
    const steps = [0.8, 1, 1.25, 1.5, 1.75];
    settings.speed = steps[(steps.indexOf(settings.speed) + 1) % steps.length];
    store.set('speed', settings.speed);
    renderSettings();
});
document.querySelectorAll('[data-offset]').forEach((b) =>
    b.addEventListener('click', () => {
        settings.offset = Math.max(-200, Math.min(200, settings.offset + Number(b.dataset.offset)));
        store.set('offset', settings.offset);
        renderSettings();
    }),
);

// ------------------------------------------------------------------ drawing

function burst(x, y, color, count, power) {
    for (let i = 0; i < count; i++) {
        const a = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.1;
        const s = (180 + Math.random() * 320) * power;
        particles.push({
            x, y,
            vx: Math.cos(a) * s,
            vy: Math.sin(a) * s,
            life: 1,
            size: 3 + Math.random() * 4,
            color: Math.random() < 0.45 ? '#c58a4f' : color, // wood chips + sparks
            spin: Math.random() * 6,
        });
    }
}

function roundRect(x, y, w, h, r) {
    g.beginPath();
    g.roundRect(x, y, w, h, r);
}

function drawTung(x, y, w, color, alpha) {
    const h = 24;
    const top = y - h / 2;
    g.globalAlpha = alpha;

    const wood = g.createLinearGradient(0, top, 0, top + h);
    wood.addColorStop(0, '#eab676');
    wood.addColorStop(1, '#9a5f2b');
    roundRect(x, top, w, h, 9);
    g.fillStyle = wood;
    g.fill();
    g.lineWidth = 2.5;
    g.strokeStyle = color;
    g.stroke();

    // Grain + a log-end ring
    g.strokeStyle = 'rgba(90, 50, 20, 0.45)';
    g.lineWidth = 1.2;
    g.beginPath();
    g.moveTo(x + 26, top + 8);
    g.lineTo(x + w - 10, top + 8);
    g.moveTo(x + 30, top + 15);
    g.lineTo(x + w - 18, top + 15);
    g.stroke();
    g.beginPath();
    g.ellipse(x + 14, y, 5, 7, 0, 0, Math.PI * 2);
    g.stroke();

    g.globalAlpha = 1;
}

function drawSahur(x, y, w, alpha, finale) {
    const h = finale ? 40 : 32;
    const top = y - h / 2;
    g.globalAlpha = alpha;
    g.shadowColor = 'rgba(253, 224, 71, 0.8)';
    g.shadowBlur = 22;

    const gold = g.createLinearGradient(0, top, 0, top + h);
    gold.addColorStop(0, '#fef08a');
    gold.addColorStop(1, '#f59e0b');
    roundRect(x, top, w, h, 12);
    g.fillStyle = gold;
    g.fill();
    g.shadowBlur = 0;
    g.lineWidth = 2;
    g.strokeStyle = '#fff7d6';
    g.stroke();

    g.fillStyle = '#5a2d0c';
    g.font = `${finale ? 20 : 17}px Bungee, sans-serif`;
    g.textAlign = 'center';
    g.textBaseline = 'middle';
    g.fillText(finale ? 'SAHUUUR!' : 'SAHUR!', x + w / 2, y + 1);

    g.globalAlpha = 1;
}

function draw(now, dt) {
    const { w, h } = view;
    const laneW = w / 4;
    const hy = hitY();
    const pps = pxPerSec();

    g.clearRect(0, 0, w, h);

    // Lanes
    for (let i = 0; i < 4; i++) {
        g.fillStyle = i % 2 ? 'rgba(255,255,255,0.035)' : 'rgba(255,255,255,0.06)';
        g.fillRect(i * laneW, 0, laneW, h);

        const on = held.has(i) || (i > 0 && i < 3 && held.has('space'));
        laneGlow[i] = on ? Math.max(laneGlow[i], 0.55) : laneGlow[i] * Math.exp(-dt * 9);
        if (laneGlow[i] > 0.01) {
            const grad = g.createLinearGradient(0, hy, 0, hy - 320);
            grad.addColorStop(0, LANE_COLORS[i] + Math.round(laneGlow[i] * 90).toString(16).padStart(2, '0'));
            grad.addColorStop(1, LANE_COLORS[i] + '00');
            g.fillStyle = grad;
            g.fillRect(i * laneW, hy - 320, laneW, 320);
        }
    }

    g.strokeStyle = 'rgba(255,255,255,0.08)';
    g.lineWidth = 1;
    for (let i = 1; i < 4; i++) {
        g.beginPath();
        g.moveTo(i * laneW, 0);
        g.lineTo(i * laneW, h);
        g.stroke();
    }

    if (game && state !== 'title') {
        const spb = game.chart.spb;

        // Beat lines — brighter on the bar
        const firstBeat = Math.ceil((now - (h - hy) / pps) / spb);
        for (let b = Math.max(0, firstBeat); ; b++) {
            const y = hy - (b * spb - now) * pps;
            if (y < -10) break;
            g.fillStyle = b % 4 === 0 ? 'rgba(255,255,255,0.14)' : 'rgba(255,255,255,0.05)';
            g.fillRect(0, y, w, b % 4 === 0 ? 2 : 1);
        }
    }

    // Receptors
    for (let i = 0; i < 4; i++) {
        const x = i * laneW + 6;
        const bw = laneW - 12;
        const on = held.has(i) || (i > 0 && i < 3 && held.has('space'));
        roundRect(x, hy - 16, bw, 32, 11);
        g.fillStyle = on ? LANE_COLORS[i] + '55' : 'rgba(10,8,30,0.6)';
        g.fill();
        g.lineWidth = 2.5;
        g.strokeStyle = LANE_COLORS[i] + (on ? 'ff' : 'aa');
        g.stroke();

        g.fillStyle = on ? '#fff' : LANE_COLORS[i];
        g.font = '14px Bungee, sans-serif';
        g.textAlign = 'center';
        g.textBaseline = 'middle';
        g.fillText(LANE_KEYS[i], x + bw / 2, hy + 44);
    }

    // Notes
    if (game && state !== 'title') {
        for (let i = Math.max(0, game.cursor - 8); i < game.notes.length; i++) {
            const n = game.notes[i];
            const y = hy - (n.t - now) * pps;
            if (y < -60) break;
            if (y > h + 40) continue;
            if (n.judged && n.judged !== 'miss') continue;

            const alpha = n.judged === 'miss' ? 0.25 : 1;
            if (n.kind === 'sahur') {
                drawSahur(laneW + 4, y, laneW * 2 - 8, alpha, n.finale);
            } else {
                drawTung(n.lane * laneW + 8, y, laneW - 16, LANE_COLORS[n.lane], alpha);
            }
        }
    }

    // Particles
    particles = particles.filter((p) => (p.life -= dt * 1.6) > 0);
    for (const p of particles) {
        p.vy += 900 * dt;
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        g.globalAlpha = Math.max(0, p.life);
        g.fillStyle = p.color;
        g.save();
        g.translate(p.x, p.y);
        g.rotate(p.spin * (1 - p.life) * 4);
        g.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
        g.restore();
    }
    g.globalAlpha = 1;
}

function animateCharacter(dt, beat) {
    const frac = ((beat % 1) + 1) % 1;
    const pulse = Math.pow(1 - frac, 4);

    pose.kick *= Math.exp(-dt * 9);
    pose.jump *= Math.exp(-dt * 5);
    pose.shake *= Math.exp(-dt * 6);

    const y = -pose.jump * 70 - pulse * 7 + Math.sin(pose.jump * Math.PI) * -10;
    const rot = pose.kick * 8 * pose.kickDir + Math.sin(performance.now() / 22) * pose.shake * 5;
    const sx = 1 + pose.kick * 0.05 + pulse * 0.015;
    const sy = 1 - pulse * 0.035 + pose.jump * 0.06;

    charEl.style.transform = `translateY(${y.toFixed(1)}px) rotate(${rot.toFixed(2)}deg) scale(${sx.toFixed(3)}, ${sy.toFixed(3)})`;
}

function frame(ts) {
    const dt = Math.min(0.05, (ts - lastFrame) / 1000);
    lastFrame = ts;

    let now = 0;
    let beat = (ts / 1000) * (96 / 60);

    if (game && (state === 'playing' || state === 'paused' || state === 'results')) {
        now = state === 'results' ? game.chart.endTime : songNow();
        beat = now / game.chart.spb;
    }

    if (state === 'playing') {
        // Anything that slid past the window is a miss
        for (let i = game.cursor; i < game.notes.length; i++) {
            const n = game.notes[i];
            if (n.t > now - WINDOW.good) break;
            if (!n.judged) {
                judge(n, 'miss');
                if (state !== 'playing') break;
            }
        }
        while (game.cursor < game.notes.length && game.notes[game.cursor].judged) {
            game.cursor++;
        }

        // Count-in: 3 · 2 · 1 · GO! over the second intro bar
        const countEl = $('#count');
        const introBeat = Math.floor(beat);
        const label = introBeat >= 4 && introBeat < 8 ? ['3', '2', '1', 'GO!'][introBeat - 4] : '';
        if (countEl.textContent !== label) {
            countEl.textContent = label;
            countEl.classList.remove('pop');
            void countEl.offsetWidth;
            if (label) countEl.classList.add('pop');
        }

        // Imsak countdown = time left in the song
        const left = Math.max(0, game.chart.endTime - now);
        $('#hud-clock').textContent = `${Math.floor(left / 60)}:${String(Math.floor(left % 60)).padStart(2, '0')}`;
        $('#hud-progress').style.setProperty('--p', Math.min(100, (now / game.chart.endTime) * 100) + '%');

        if (now > game.chart.endTime) {
            finish(false);
        }
    }

    animateCharacter(dt, beat);
    draw(now, dt);
    requestAnimationFrame(frame);
}

// --------------------------------------------------------------------- boot

renderBests();
renderSettings();
showScreen('#title-screen');
document.body.dataset.state = 'title';
document.fonts?.ready.then(resize);
requestAnimationFrame(frame);

// Dev-only handle for automated play-testing; stripped from production builds.
if (import.meta.env.DEV) {
    window.__tripleT = { game: () => game, songNow: () => (game ? songNow() : 0), state: () => state };
}
