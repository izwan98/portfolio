/**
 * Every sound in the game is synthesised with the Web Audio API — no samples,
 * so nothing to license and nothing to download.
 */

// Hijaz on D (the "Middle Eastern" scale): D Eb F# G A Bb C
const HZ = {
    C2: 65.41, D2: 73.42, Eb2: 77.78,
    D4: 293.66, Eb4: 311.13, Fs4: 369.99, G4: 392.0, A4: 440.0, Bb4: 466.16, C5: 523.25, D5: 587.33,
};

// Pitch of the wooden log per lane, low → high (lane 1/2 double as SAHUR keys)
const LANE_PITCH = [176, 208, 247, 294];

export class AudioEngine {
    ctx = null;
    voice = true;

    init() {
        if (this.ctx) {
            return this.ctx;
        }

        const ctx = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: 'interactive' });
        this.ctx = ctx;

        const comp = ctx.createDynamicsCompressor();
        comp.threshold.value = -14;
        comp.knee.value = 10;
        comp.ratio.value = 4;
        comp.attack.value = 0.003;
        comp.release.value = 0.15;

        this.master = ctx.createGain();
        this.master.gain.value = 0.9;
        this.master.connect(comp).connect(ctx.destination);

        this.musicBus = this.gain(0.5, this.master);
        this.sfxBus = this.gain(1, this.master);

        // Short room so the log knocks ring out a little, like a night-time kampung
        this.reverb = ctx.createConvolver();
        this.reverb.buffer = this.impulse(1.8, 2.6);
        this.reverb.connect(this.gain(0.28, this.master));

        const len = ctx.sampleRate;
        this.noise = ctx.createBuffer(1, len, ctx.sampleRate);
        const data = this.noise.getChannelData(0);
        for (let i = 0; i < len; i++) {
            data[i] = Math.random() * 2 - 1;
        }

        this.loadVoice();

        return ctx;
    }

    /** Seconds between "scheduled" and "heard" — used to line visuals and judging up with the speakers. */
    get latency() {
        const c = this.ctx;
        return c ? c.outputLatency || c.baseLatency || 0 : 0;
    }

    get now() {
        return this.ctx.currentTime;
    }

    // ---------------------------------------------------------------- helpers

    gain(value, to) {
        const g = this.ctx.createGain();
        g.gain.value = value;
        if (to) {
            g.connect(to);
        }
        return g;
    }

    impulse(seconds, decay) {
        const rate = this.ctx.sampleRate;
        const len = Math.floor(rate * seconds);
        const buf = this.ctx.createBuffer(2, len, rate);
        for (let ch = 0; ch < 2; ch++) {
            const d = buf.getChannelData(ch);
            for (let i = 0; i < len; i++) {
                d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, decay);
            }
        }
        return buf;
    }

    /** Percussive envelope on a fresh gain node. */
    env(t, peak, attack, decay, to) {
        const g = this.ctx.createGain();
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(Math.max(peak, 0.0002), t + attack);
        g.gain.exponentialRampToValueAtTime(0.0001, t + attack + decay);
        g.connect(to);
        return g;
    }

    osc(type, freq, t, dur, to) {
        const o = this.ctx.createOscillator();
        o.type = type;
        o.frequency.setValueAtTime(freq, t);
        o.connect(to);
        o.start(t);
        o.stop(t + dur + 0.05);
        return o;
    }

    noiseBurst(t, dur, filterType, freq, q, to) {
        const src = this.ctx.createBufferSource();
        src.buffer = this.noise;
        const f = this.ctx.createBiquadFilter();
        f.type = filterType;
        f.frequency.value = freq;
        f.Q.value = q;
        src.connect(f).connect(to);
        src.start(t, Math.random() * 0.5, dur + 0.05);
        return f;
    }

    // ----------------------------------------------------------------- voices

    /** The "TUNG": a hollow wooden log (kentongan) knock. */
    tung(t, lane = 1, vel = 1) {
        const base = LANE_PITCH[lane] ?? LANE_PITCH[1];
        const out = this.gain(vel, this.sfxBus);
        out.connect(this.reverb);

        // Body — fast pitch drop gives the woody "t-UNG"
        const body = this.osc('sine', base * 1.9, t, 0.32, this.env(t, 0.95, 0.002, 0.26, out));
        body.frequency.exponentialRampToValueAtTime(base, t + 0.028);

        // Hollow resonance overtone
        this.osc('sine', base * 2.76, t, 0.12, this.env(t, 0.22, 0.002, 0.09, out));

        // Stick click
        this.noiseBurst(t, 0.04, 'bandpass', 1900, 1.4, this.env(t, 0.55, 0.001, 0.03, out));
    }

    /** Bedug — the big mosque drum. */
    bedug(t, vel = 1, to = this.musicBus) {
        const out = this.gain(vel, to);
        const o = this.osc('sine', 105, t, 0.7, this.env(t, 1, 0.003, 0.6, out));
        o.frequency.exponentialRampToValueAtTime(46, t + 0.16);
        this.noiseBurst(t, 0.1, 'lowpass', 500, 0.7, this.env(t, 0.35, 0.002, 0.08, out));
    }

    shaker(t, vel = 0.4) {
        this.noiseBurst(t, 0.07, 'highpass', 6500, 0.7, this.env(t, 0.16 * vel, 0.006, 0.05, this.musicBus));
    }

    bass(t, freq, dur) {
        const f = this.ctx.createBiquadFilter();
        f.type = 'lowpass';
        f.frequency.value = 520;
        f.connect(this.env(t, 0.42, 0.008, dur, this.musicBus));
        this.osc('triangle', freq, t, dur, f);
        this.osc('sine', freq / 2, t, dur, f);
    }

    bell(t, freq, dur = 0.5, vel = 1) {
        const out = this.env(t, 0.1 * vel, 0.004, dur, this.musicBus);
        out.connect(this.reverb);
        this.osc('triangle', freq, t, dur, out);
        this.osc('sine', freq * 2.005, t, dur * 0.6, this.env(t, 0.04 * vel, 0.004, dur * 0.5, this.musicBus));
    }

    tick(t, accent = false) {
        this.osc('sine', accent ? 1320 : 990, t, 0.06, this.env(t, 0.25, 0.002, 0.05, this.musicBus));
    }

    /** SAHUR hit: bedug + crash + a brass stab on the D Hijaz chord. */
    sahur(t, vel = 1) {
        const out = this.gain(vel, this.sfxBus);
        out.connect(this.reverb);

        this.bedug(t, 1.1, out);
        this.noiseBurst(t, 0.5, 'bandpass', 3200, 0.45, this.env(t, 0.35, 0.002, 0.42, out));

        const f = this.ctx.createBiquadFilter();
        f.type = 'lowpass';
        f.frequency.setValueAtTime(3400, t);
        f.frequency.exponentialRampToValueAtTime(700, t + 0.35);
        f.connect(this.env(t, 0.16, 0.01, 0.38, out));
        [HZ.D4, HZ.Fs4, HZ.A4].forEach((hz) => this.osc('sawtooth', hz, t, 0.42, f));
    }

    /** Descending "wah wah" when the village oversleeps. */
    fail() {
        const t = this.now + 0.05;
        [HZ.A4, HZ.G4, HZ.Fs4, HZ.Eb4].forEach((hz, i) => {
            const at = t + i * 0.32;
            const f = this.ctx.createBiquadFilter();
            f.type = 'lowpass';
            f.frequency.value = 1100;
            f.connect(this.env(at, 0.22, 0.03, i === 3 ? 0.9 : 0.28, this.sfxBus));
            const o = this.osc('sawtooth', hz / 2, at, i === 3 ? 1 : 0.32, f);
            if (i === 3) {
                o.frequency.linearRampToValueAtTime(hz / 2.2, at + 0.9);
            }
        });
    }

    fanfare() {
        const t = this.now + 0.05;
        [HZ.D4, HZ.Fs4, HZ.A4, HZ.D5].forEach((hz, i) => this.bell(t + i * 0.11, hz, 0.9, 2.2));
        this.bedug(t + 0.44, 1, this.sfxBus);
    }

    // ------------------------------------------------------------------ speech

    loadVoice() {
        if (!('speechSynthesis' in window)) {
            return;
        }

        // Prefer a Malay or Indonesian voice so "sahur" is pronounced properly.
        const pick = () => {
            const voices = speechSynthesis.getVoices();
            this.speechVoice =
                voices.find((v) => /^(ms|id)/i.test(v.lang)) ?? voices.find((v) => /^en/i.test(v.lang)) ?? null;
        };

        pick();
        speechSynthesis.addEventListener?.('voiceschanged', pick);
    }

    say(text, { rate = 1.05, pitch = 0.4 } = {}) {
        if (!this.voice || !('speechSynthesis' in window)) {
            return;
        }

        speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        if (this.speechVoice) {
            u.voice = this.speechVoice;
            u.lang = this.speechVoice.lang;
        }
        u.rate = rate;
        u.pitch = pitch;
        speechSynthesis.speak(u);
    }

    hush() {
        if ('speechSynthesis' in window) {
            speechSynthesis.cancel();
        }
    }
}

/**
 * Backing groove, scheduled ahead of time on the audio clock in 16th-note steps.
 * The tungs themselves are NOT in the track: the player plays them.
 */
export class Music {
    constructor(engine, chart) {
        this.engine = engine;
        this.chart = chart;
        this.timer = null;
    }

    start(songStart) {
        this.songStart = songStart;
        this.step = 0;
        this.totalSteps = this.chart.totalBeats * 4;
        this.timer = setInterval(() => this.pump(), 25);
        this.pump();
    }

    stop() {
        clearInterval(this.timer);
        this.timer = null;
    }

    pump() {
        const stepDur = this.chart.spb / 4;
        // While the context is suspended (paused) currentTime freezes, so nothing new gets queued.
        const horizon = this.engine.now + 0.12;

        while (this.step < this.totalSteps) {
            const t = this.songStart + this.step * stepDur;
            if (t > horizon) {
                return;
            }
            this.play(this.step, t);
            this.step++;
        }

        this.stop();
    }

    play(step, t) {
        const e = this.engine;
        const bar = Math.floor(step / 16);
        const s = step % 16;
        const introBars = this.chart.introBeats / 4;
        const lastBar = this.chart.totalBeats / 4 - 1;
        const spb = this.chart.spb;

        // Intro: a lone bedug, then a count-in
        if (bar < introBars) {
            if (bar === 0 && s === 0) {
                e.bedug(t, 1);
            }
            if (bar === introBars - 1 && s % 4 === 0) {
                e.tick(t, s === 0);
                e.bedug(t, 0.5);
            }
            return;
        }

        // Finale bar: one big ending hit, then ring out
        if (bar === lastBar) {
            if (s === 0) {
                e.bedug(t, 1.2);
                [HZ.D4, HZ.A4, HZ.D5].forEach((hz) => e.bell(t, hz, 1.6, 1.6));
            }
            return;
        }

        const groove = bar - introBars;

        // Drums
        if (s === 0) e.bedug(t, 1);
        if (s === 8) e.bedug(t, 0.75);
        if (s === 14 && groove % 2 === 1) e.bedug(t, 0.45);
        if (s % 2 === 0) e.shaker(t, s % 4 === 2 ? 1 : 0.55);

        // Bass: D – D – Eb – C, one root per bar
        const root = [HZ.D2, HZ.D2, HZ.Eb2, HZ.C2][groove % 4];
        if (s === 0) e.bass(t, root, spb * 1.2);
        if (s === 6) e.bass(t, root, spb * 0.5);
        if (s === 10) e.bass(t, root * 1.5, spb * 0.8);

        // Bell motif every other bar pair, so it doesn't get tiring
        if (groove % 4 < 2) {
            const motif = groove % 2 === 0
                ? { 0: HZ.A4, 3: HZ.Bb4, 6: HZ.A4, 8: HZ.G4, 10: HZ.Fs4, 12: HZ.G4 }
                : { 0: HZ.Fs4, 4: HZ.Eb4, 8: HZ.D4 };
            if (motif[s]) {
                e.bell(t, motif[s], spb * 0.9);
            }
        }
    }
}
