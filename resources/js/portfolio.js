import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

Alpine.plugin(collapse);

document.documentElement.classList.add('js');

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Page shell: mobile drawer + scroll-spy for the explorer sidebar.
 */
Alpine.data('portfolio', () => ({
    drawer: false,
    active: 'home',

    init() {
        const targets = document.querySelectorAll('[data-spy]');

        // Pick the spied element closest to the upper third of the viewport.
        const update = () => {
            const line = window.innerHeight * 0.35;
            let current = targets[0]?.id;

            targets.forEach((el) => {
                if (el.getBoundingClientRect().top <= line) {
                    current = el.id;
                }
            });

            this.active = current;
        };

        window.addEventListener('scroll', update, { passive: true });
        update();
    },

    go(id) {
        this.drawer = false;
        document.getElementById(id)?.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth' });
    },
}));

/**
 * Hero pill that types and deletes a rotating list of phrases.
 */
Alpine.data('typewriter', (words) => ({
    text: '',

    init() {
        if (reducedMotion) {
            this.text = words[0];
            return;
        }

        let word = 0;
        let char = 0;
        let deleting = false;

        const tick = () => {
            const full = words[word];

            char += deleting ? -1 : 1;
            this.text = full.slice(0, char);

            let delay = deleting ? 35 : 70;

            if (!deleting && char === full.length) {
                deleting = true;
                delay = 1800;
            } else if (deleting && char === 0) {
                deleting = false;
                word = (word + 1) % words.length;
                delay = 350;
            }

            setTimeout(tick, delay);
        };

        setTimeout(tick, 600);
    },
}));

/**
 * About section: orbs drifting on their own, linked to the first (hub) node.
 */
Alpine.data('orbGraph', (nodes) => ({
    init() {
        const orbs = [...this.$el.querySelectorAll('[data-orb]')];
        const lines = [...this.$el.querySelectorAll('[data-line]')];
        const pointer = { x: 0, y: 0 };

        this.$el.addEventListener('pointermove', (e) => {
            const r = this.$el.getBoundingClientRect();
            pointer.x = (e.clientX - r.left) / r.width - 0.5;
            pointer.y = (e.clientY - r.top) / r.height - 0.5;
        });

        this.$el.addEventListener('pointerleave', () => {
            pointer.x = 0;
            pointer.y = 0;
        });

        const frame = (t) => {
            const s = t / 1000;

            const positions = nodes.map((n, i) => {
                const drift = reducedMotion ? 0 : 1;
                const depth = i === 0 ? 2 : 5;

                return {
                    x: n.x + Math.sin(s * 0.6 + i * 1.7) * 2.2 * drift + pointer.x * depth,
                    y: n.y + Math.cos(s * 0.5 + i * 2.3) * 2.2 * drift + pointer.y * depth,
                };
            });

            positions.forEach((p, i) => {
                orbs[i].style.left = `${p.x}%`;
                orbs[i].style.top = `${p.y}%`;
            });

            lines.forEach((line, i) => {
                const to = positions[i + 1];
                line.setAttribute('x1', positions[0].x);
                line.setAttribute('y1', positions[0].y);
                line.setAttribute('x2', to.x);
                line.setAttribute('y2', to.y);
            });

            if (!reducedMotion) {
                requestAnimationFrame(frame);
            }
        };

        requestAnimationFrame(frame);
    },
}));

/**
 * Experience timeline: fill the centre rail as the section scrolls past.
 */
Alpine.data('timeline', () => ({
    progress: 0,

    init() {
        const update = () => {
            const r = this.$el.getBoundingClientRect();
            const start = window.innerHeight * 0.6;
            const value = (start - r.top) / r.height;
            this.progress = Math.min(1, Math.max(0, value));
        };

        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    },
}));

window.Alpine = Alpine;
Alpine.start();

// Reveal-on-scroll for anything marked [data-reveal].
const revealer = new IntersectionObserver(
    (entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                revealer.unobserve(entry.target);
            }
        });
    },
    { threshold: 0.15, rootMargin: '0px 0px -40px 0px' },
);

document.querySelectorAll('[data-reveal]').forEach((el) => revealer.observe(el));
