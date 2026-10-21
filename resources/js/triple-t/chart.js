/**
 * Note charts. Each phrase is a bar-aligned rhythm cell (in beats) built from
 * the "Tung, tung, tung… SAHUR!" call; songs are just lists of phrases.
 */

const range = (from, to, step) => {
    const out = [];
    for (let b = from; b <= to + 1e-9; b += step) {
        out.push(+b.toFixed(3));
    }
    return out;
};

const PHRASES = {
    rest: { len: 4, tung: [], sahur: [] },
    classic: { len: 4, tung: [0, 1, 2], sahur: [3] },
    // The full meme: nine tungs, then sahur
    nineSlow: { len: 12, tung: range(0, 8, 1), sahur: [9] },
    nine: { len: 8, tung: range(0, 4, 0.5), sahur: [5] },
    eighths: { len: 4, tung: [0, 0.5, 1, 1.5, 2], sahur: [3] },
    synco: { len: 4, tung: [0, 0.75, 1.5, 2, 2.5], sahur: [3] },
    double: { len: 4, tung: [0, 0.5, 1], sahur: [2, 3] },
    gallop: { len: 4, tung: [0, 0.5, 0.75, 1, 1.5, 1.75, 2, 2.5], sahur: [3] },
    roll: { len: 4, tung: [0, 0.25, 0.5, 0.75, 1, 1.5, 2, 2.5], sahur: [3] },
};

const x = (name, times) => Array(times).fill(name);

export const SONGS = {
    easy: {
        label: 'Easy',
        blurb: 'A gentle knock on the door.',
        bpm: 96,
        seed: 7,
        phrases: [
            ...x('classic', 4), 'rest', 'nineSlow',
            ...x('classic', 4), 'rest', 'nineSlow',
            ...x('classic', 2), ...x('double', 2),
        ],
    },
    normal: {
        label: 'Normal',
        blurb: 'The whole street hears you.',
        bpm: 116,
        seed: 21,
        phrases: [
            ...x('classic', 2), ...x('eighths', 2), 'nine',
            ...x('classic', 2), ...x('synco', 2), 'nine',
            'eighths', 'double', ...x('synco', 2), 'nine',
            ...x('eighths', 2), ...x('double', 2), 'nine',
        ],
    },
    sahur: {
        label: 'Sahur Mode',
        blurb: 'Imsak is in five minutes. GO.',
        bpm: 140,
        seed: 99,
        phrases: [
            ...x('eighths', 2), 'nine', ...x('synco', 2), ...x('gallop', 2), 'nine',
            ...x('double', 2), ...x('roll', 2), 'nine', ...x('gallop', 2),
            ...x('synco', 2), ...x('roll', 2), 'nine', ...x('double', 2),
            ...x('gallop', 2), ...x('roll', 2), 'nine', 'nine',
        ],
    },
};

// Small deterministic PRNG so each chart is the same every play (learnable).
function mulberry32(seed) {
    return () => {
        seed |= 0;
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export function buildChart(key) {
    const song = SONGS[key];
    const spb = 60 / song.bpm;
    const introBeats = 8;
    const rng = mulberry32(song.seed);

    // Lay phrases end to end
    const events = [];
    let cursor = introBeats;
    for (const name of song.phrases) {
        const p = PHRASES[name];
        p.tung.forEach((b) => events.push({ beat: cursor + b, kind: 'tung' }));
        p.sahur.forEach((b) => events.push({ beat: cursor + b, kind: 'sahur' }));
        cursor += p.len;
    }

    // Finale: one last SAHUR on the downbeat of the closing bar
    events.push({ beat: cursor, kind: 'sahur', finale: true });
    const totalBeats = cursor + 4;

    events.sort((a, b) => a.beat - b.beat);

    // Lanes: avoid repeats, alternate hands on fast runs, go outside after a SAHUR
    let prevLane = -1;
    let prevBeat = -9;
    let prevKind = null;
    const notes = events.map((ev) => {
        let lane = -1;

        if (ev.kind === 'tung') {
            const gap = ev.beat - prevBeat;
            let pool = [0, 1, 2, 3].filter((l) => l !== prevLane);

            if (prevKind === 'sahur' && gap <= 0.5) {
                pool = [0, 3];
            } else if (gap <= 0.5 && prevLane >= 0) {
                pool = prevLane < 2 ? [2, 3] : [0, 1];
            }

            lane = pool[Math.floor(rng() * pool.length)];
            prevLane = lane;
        } else {
            prevLane = -1;
        }

        prevBeat = ev.beat;
        prevKind = ev.kind;

        return { t: ev.beat * spb, beat: ev.beat, lane, kind: ev.kind, finale: !!ev.finale };
    });

    return {
        key,
        label: song.label,
        bpm: song.bpm,
        spb,
        introBeats,
        totalBeats,
        endTime: totalBeats * spb,
        notes,
        sahurCount: notes.filter((n) => n.kind === 'sahur').length,
    };
}
