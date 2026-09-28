/*
 * Twinkling stars and shooting stars on a 2D canvas, shared by the login page's space scene
 * (controllers/login_globe_controller.js) and the app sidebar (controllers/starfield_controller.js).
 *
 * Positions are fractions of the canvas, so the field survives resizes. Pass a seed to get the same
 * stars every time (the sidebar is re-rendered on each page visit and must not reshuffle).
 */

/** Small deterministic PRNG (mulberry32): same seed, same sequence. */
function seeded(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const METEOR_DEFAULTS = {
    gap: [0.3, 1.2],        // seconds between two shooting stars
    max: 5,                 // at once
    area: [0.15, 1, 0, 0.6], // where they start: x from, x to, y from, y to (fractions)
    distance: [200, 420],   // px travelled
    tail: [70, 160],        // px
    life: [0.6, 1.3],       // seconds
};

export class Starfield {
    /**
     * @param {{count: number, seed?: number, x?: [number, number], meteors?: object|false}} options
     *        x: horizontal band the stars fill (fractions); meteors: overrides of METEOR_DEFAULTS, or false for none
     */
    constructor({ count, seed, x = [0, 1], meteors = {} }) {
        const random = undefined === seed ? Math.random : seeded(seed);
        this.stars = Array.from({ length: count }, () => ({
            x: x[0] + random() * (x[1] - x[0]),
            y: random(),
            r: random() < 0.9 ? 0.4 + random() * 0.8 : 1.2 + random() * 0.8,
            phase: random() * 2 * Math.PI,
            speed: 0.5 + random() * 1.5,
        }));
        this.meteorOptions = false === meteors ? false : { ...METEOR_DEFAULTS, ...meteors };
        this.meteors = [];
        this.nextMeteorAt = 0;
    }

    /** @param {number} now seconds; 0 for a still frame (reduced motion) */
    drawStars(ctx, w, h, now) {
        ctx.fillStyle = '#fff';
        for (const s of this.stars) {
            ctx.globalAlpha = 0.35 + 0.65 * Math.abs(Math.sin(s.phase + now * s.speed));
            ctx.beginPath(); ctx.arc(s.x * w, s.y * h, s.r, 0, 2 * Math.PI); ctx.fill();
        }
        ctx.globalAlpha = 1;
    }

    /** Shooting stars, each with its own speed, length and angle, travelling right to left. */
    drawMeteors(ctx, w, h, now) {
        const o = this.meteorOptions;
        if (!o) return;
        const between = ([from, to]) => from + Math.random() * (to - from);
        if (now >= this.nextMeteorAt && this.meteors.length < o.max) {
            const angle = 0.35 + Math.random() * 0.25; // radians below horizontal
            this.meteors.push({
                x: w * between([o.area[0], o.area[1]]), y: h * between([o.area[2], o.area[3]]), start: now,
                life: between(o.life), distance: between(o.distance), tail: between(o.tail),
                dx: -Math.cos(angle), dy: Math.sin(angle), width: 1 + Math.random() * 1.2,
            });
            this.nextMeteorAt = now + between(o.gap);
        }

        this.meteors = this.meteors.filter((m) => now - m.start < m.life);
        for (const m of this.meteors) {
            const t = (now - m.start) / m.life;
            const x = m.x + m.dx * m.distance * t, y = m.y + m.dy * m.distance * t;
            const tx = x - m.dx * m.tail, ty = y - m.dy * m.tail;
            const alpha = t < 0.15 ? t / 0.15 : 1 - (t - 0.15) / 0.85; // fade in quickly, then out
            const tail = ctx.createLinearGradient(x, y, tx, ty);
            tail.addColorStop(0, `rgba(255,255,255,${alpha})`);
            tail.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.strokeStyle = tail;
            ctx.lineWidth = m.width;
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(tx, ty); ctx.stroke();
        }
    }
}
