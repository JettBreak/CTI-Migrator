import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * The sign-in page's scene in the 8-bit console theme (App\Enum\AppTheme, "city" scenery): a pixel-art business
 * district at the bottom of the window, the company's headquarters tower on the right with the logo near its top,
 * a far skyline behind, street lamps, and cars driving both ways on the road.
 *
 * The sky follows the time of day in the app's timezone (time-zone value): blue with white clouds by day, orange
 * and pink at dawn and dusk, deep blue at night, when office windows light up (a few switching on and off) and the
 * lamps and car lights glow. The sky is drawn in bands with dithered seams, like a console game.
 *
 * Everything is drawn in whole "art pixels" on a small canvas (PIXEL CSS pixels each) that CSS scales up without
 * smoothing (image-rendering: pixelated), so the edges stay crisp. The city is generated from a fixed seed, so it is
 * the same on every visit; a resize builds it anew for the new size. Paused while the tab is hidden; a still frame
 * for users who prefer reduced motion. Dispatches "pixel-city:ready" once the first frame is drawn, for the page
 * loader (see page_loader_controller.js).
 *
 * <canvas data-controller="pixel-city" data-pixel-city-time-zone-value="Asia/Manila"
 *         data-pixel-city-logo-value="/images/logo-32.png"></canvas>
 */
const PIXEL = 4;

/** Sky bands from the top down, building and window colours, by time of day. */
const PERIODS = {
    day: {
        sky: ['#0058f8', '#1878f8', '#3c9cfc', '#3cbcfc', '#68ccfc', '#a4e4fc'],
        far: '#7cb8e0', buildings: ['#5c6c8c', '#6c5c58', '#48587c', '#7c7c90', '#58687c'],
        windows: ['#a4e4fc', '#d8f8fc'], unlit: '#2c4c7c', lit: 0, cloud: ['#fcfcfc', '#c4e8fc'], road: '#3c3c48', glow: false,
    },
    dawn: {
        sky: ['#3c3c9c', '#6844a8', '#a8508c', '#e47c6c', '#fca868', '#fcd8a8'],
        far: '#9c6c8c', buildings: ['#4c3c5c', '#5c4450', '#3c3c5c', '#644c5c', '#48405c'],
        windows: ['#fcd8a8', '#fce0a8'], unlit: '#2c2444', lit: 0.25, cloud: ['#fcd0d8', '#f8a8b8'], road: '#34303c', glow: true,
    },
    dusk: {
        sky: ['#24185c', '#4c1c74', '#a8105c', '#e43c3c', '#f87c1c', '#f8b800'],
        far: '#8c3c5c', buildings: ['#3c2440', '#4c2c3c', '#2c2448', '#542c44', '#38283c'],
        windows: ['#fcd000', '#fce0a8'], unlit: '#24182c', lit: 0.35, cloud: ['#f878a8', '#c83c7c'], road: '#2c2430', glow: true,
    },
    night: {
        sky: ['#000014', '#04042a', '#0b0b3c', '#14144c', '#1c1c5c', '#2c2c6c'],
        far: '#1c1c48', buildings: ['#14142c', '#1c1830', '#101030', '#202038', '#181828'],
        windows: ['#fcd000', '#fce0a8'], unlit: '#0c0c20', lit: 0.45, cloud: ['#24245c', '#1c1c4c'], road: '#14141c', glow: true,
    },
};
const CAR_COLORS = ['#e40058', '#0058f8', '#f8b800', '#00a800', '#fcfcfc', '#6844fc'];

/** A seeded random number generator: the same city every time. */
function random(seed) {
    return () => (seed = (seed * 16807) % 2147483647) / 2147483647;
}

export default class extends Controller {
    static values = { timeZone: String, logo: String };

    connect() {
        this.ctx = this.element.getContext('2d');
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (this.logoValue) {
            this.logo = new Image();
            this.logo.src = this.logoValue;
        }
        this.onResize = () => { this.build(); this.draw(this.now()); };
        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.start());
        window.addEventListener('resize', this.onResize);
        document.addEventListener('visibilitychange', this.onVisibilityChange);

        this.build();
        this.start();
        requestAnimationFrame(() => requestAnimationFrame(() => this.dispatch('ready')));
    }

    disconnect() {
        this.stop();
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    now() {
        return this.still ? 0 : performance.now() / 1000;
    }

    start() {
        if (this.still) { this.draw(0); return; }
        if (this.frame) return;
        const tick = () => { this.frame = requestAnimationFrame(tick); this.draw(this.now()); };
        this.frame = requestAnimationFrame(tick);
    }

    stop() {
        cancelAnimationFrame(this.frame);
        this.frame = null;
    }

    /** The hour (0–23.99) in the app's timezone, or the browser's if it is not set or not known. */
    hour() {
        try {
            const parts = new Intl.DateTimeFormat('en-GB', { timeZone: this.timeZoneValue || undefined, hour: 'numeric', minute: 'numeric', hourCycle: 'h23' })
                .formatToParts(new Date());
            const part = (type) => Number(parts.find((p) => p.type === type)?.value ?? 0);

            return part('hour') + part('minute') / 60;
        } catch {
            const now = new Date();

            return now.getHours() + now.getMinutes() / 60;
        }
    }

    period() {
        const h = this.hour();
        if (h >= 5 && h < 7) return 'dawn';
        if (h >= 7 && h < 17) return 'day';
        if (h >= 17 && h < 19.5) return 'dusk';

        return 'night';
    }

    /** Lays out the city for the window's size: the ground, both skylines, the tower, lamps, clouds and cars. */
    build() {
        const w = this.w = Math.ceil(this.element.clientWidth / PIXEL);
        const h = this.h = Math.ceil(this.element.clientHeight / PIXEL);
        this.element.width = w;
        this.element.height = h;
        this.ctx.imageSmoothingEnabled = false;
        const rand = random(19870815);
        this.ground = h - 16; // the top of the pavement; the road below it reaches the bottom

        // Far skyline: plain blocks, a little taller towards the right.
        this.far = [];
        for (let x = -4; x < w + 4;) {
            const bw = 8 + Math.floor(rand() * 14);
            this.far.push({ x, w: bw, h: Math.floor(h * (0.22 + rand() * 0.16 + 0.12 * x / w)), spire: rand() < 0.15 });
            x += bw + Math.floor(rand() * 3) - 1;
        }

        // The headquarters: a stepped tower in the right third, the logo near its top.
        const hqW = Math.max(30, Math.min(48, Math.floor(w * 0.09)));
        const hqH = Math.floor(Math.min(h * 0.68, h - 40));
        this.hq = { x: Math.floor(w * 0.74 - hqW / 2), w: hqW, h: hqH };

        // Near buildings with windows, taller towards the right, leaving room for the tower.
        this.buildings = [];
        for (let x = -6; x < w + 6;) {
            const bw = 14 + Math.floor(rand() * 18);
            const bh = Math.floor(h * (0.16 + rand() * 0.14 + 0.2 * Math.max(0, x / w - 0.3)));
            if (x + bw > this.hq.x - 2 && x < this.hq.x + this.hq.w + 2) {
                x = this.hq.x + this.hq.w + 2 + Math.floor(rand() * 2);
                continue;
            }
            this.buildings.push(this.building(x, bw, Math.min(bh, Math.floor(this.hq.h * 0.8)), rand));
            x += bw + 1 + Math.floor(rand() * 3);
        }
        this.tower = this.building(this.hq.x, this.hq.w, this.hq.h, rand, true);

        this.lamps = Array.from({ length: Math.ceil(w / 40) }, (_, i) => 12 + i * 40 + Math.floor(rand() * 8));
        this.clouds = Array.from({ length: Math.max(3, Math.round(w / 110)) }, () => ({
            x: rand() * w, y: 6 + rand() * h * 0.3, size: 2 + Math.floor(rand() * 3), speed: 1 + rand() * 2.5,
        }));
        this.cars = Array.from({ length: Math.max(3, Math.round(w / 90)) }, (_, i) => ({
            lane: i % 2, at: rand(), speed: 14 + rand() * 18, color: CAR_COLORS[Math.floor(rand() * CAR_COLORS.length)], long: rand() < 0.25,
        }));
        this.flickerAt = 0;
        this.colors = null; // looked up again on the next frame
    }

    /** A building's shape: its colour, roof, and which windows are lit at night (some in each). */
    building(x, w, h, rand, hq = false) {
        const columns = Math.floor((w - 3) / 3), rows = Math.floor((h - (hq ? 22 : 6)) / 4);

        return {
            x, w, h, hq, columns, rows,
            shade: Math.floor(rand() * 5),
            roof: hq ? 'tower' : (rand() < 0.3 ? 'tank' : rand() < 0.3 ? 'step' : rand() < 0.3 ? 'antenna' : 'flat'),
            windows: Array.from({ length: columns * rows }, () => rand()),
        };
    }

    draw(now) {
        const { ctx, w, h } = this;
        // The time of day changes slowly: look it up once a minute, not every frame.
        if (!this.colors || now - this.periodAt > 60 || now < this.periodAt) {
            this.colors = PERIODS[this.period()];
            this.periodAt = now;
        }
        const colors = this.colors;

        this.drawSky(colors);
        for (const cloud of this.clouds) this.drawCloud(cloud, now, colors);

        ctx.fillStyle = colors.far;
        for (const b of this.far) {
            ctx.fillRect(b.x, this.ground - b.h, b.w, b.h);
            if (b.spire) ctx.fillRect(b.x + Math.floor(b.w / 2), this.ground - b.h - 5, 1, 5);
        }

        // At night a few windows switch on or off now and then.
        if (colors.lit && now - this.flickerAt > 1.4) {
            this.flickerAt = now;
            const b = this.buildings[Math.floor(Math.random() * this.buildings.length)];
            if (b?.windows.length) b.windows[Math.floor(Math.random() * b.windows.length)] = Math.random();
        }
        for (const b of this.buildings) this.drawBuilding(b, colors, now);
        this.drawBuilding(this.tower, colors, now);
        this.drawStreet(colors, now);
    }

    /** Six bands of sky down to the horizon, each seam a checkerboard row so the bands blend like dithering. */
    drawSky(colors) {
        const { ctx, w } = this;
        const bands = colors.sky, band = Math.ceil(this.ground / bands.length);
        bands.forEach((color, i) => {
            ctx.fillStyle = color;
            ctx.fillRect(0, i * band, w, band);
        });
        for (let i = 1; i < bands.length; i++) {
            ctx.fillStyle = bands[i - 1];
            const y = i * band;
            for (let x = 0; x < w; x += 2) ctx.fillRect(x, y, 1, 1);
            for (let x = 1; x < w; x += 4) ctx.fillRect(x, y + 1, 1, 1);
        }
    }

    /** A blocky cloud drifting right, wrapping round; $size scales it. */
    drawCloud(cloud, now, colors) {
        const { ctx } = this;
        const s = cloud.size, span = this.w + 20 * s;
        const x = Math.floor(((cloud.x + now * cloud.speed) % span) - 10 * s), y = Math.floor(cloud.y);
        ctx.fillStyle = colors.cloud[1];
        ctx.fillRect(x, y + 3 * s, 14 * s, s);
        ctx.fillStyle = colors.cloud[0];
        ctx.fillRect(x + s, y + s, 12 * s, 2 * s);
        ctx.fillRect(x + 3 * s, y, 5 * s, s);
        ctx.fillRect(x + 8 * s, y - s, 3 * s, 2 * s);
    }

    drawBuilding(b, colors, now) {
        const { ctx } = this;
        const top = this.ground - b.h;
        const body = b.hq ? colors.buildings[2] : colors.buildings[b.shade];
        ctx.fillStyle = body;
        if (b.roof === 'tower') {
            // Two setbacks and a mast with a red light that blinks.
            const inset = Math.floor(b.w / 6);
            ctx.fillRect(b.x, top + 12, b.w, b.h - 12);
            ctx.fillRect(b.x + inset, top + 5, b.w - 2 * inset, 8);
            ctx.fillRect(b.x + 2 * inset, top, b.w - 4 * inset, 6);
            ctx.fillRect(b.x + Math.floor(b.w / 2), top - 10, 1, 10);
            if (this.still || Math.floor(now * 1.2) % 2 === 0) {
                ctx.fillStyle = '#e40058';
                ctx.fillRect(b.x + Math.floor(b.w / 2), top - 11, 1, 1);
            }
            // The company's logo, on a light panel, over the windows.
            const size = Math.min(16, b.w - 8), lx = b.x + Math.floor((b.w - size) / 2), ly = top + 14;
            ctx.fillStyle = '#fcfcfc';
            ctx.fillRect(lx - 1, ly - 1, size + 2, size + 2);
            if (this.logo?.complete && this.logo.naturalWidth) ctx.drawImage(this.logo, lx, ly, size, size);
        } else {
            ctx.fillRect(b.x, top, b.w, b.h);
            // A lighter edge on the left, as if lit from there.
            ctx.fillStyle = '#ffffff14';
            ctx.fillRect(b.x, top, 1, b.h);
            ctx.fillStyle = body;
            if (b.roof === 'tank') { ctx.fillRect(b.x + 3, top - 4, 5, 3); ctx.fillRect(b.x + 4, top - 1, 1, 1); ctx.fillRect(b.x + 6, top - 1, 1, 1); }
            if (b.roof === 'step') ctx.fillRect(b.x + 3, top - 3, b.w - 6, 3);
            if (b.roof === 'antenna') ctx.fillRect(b.x + b.w - 4, top - 6, 1, 6);
        }

        // Windows: 2×2 in a grid, three pixels apart across, four down.
        const windowTop = top + (b.hq ? 34 : 4);
        for (let row = 0; row < b.rows; row++) {
            const y = windowTop + row * 4;
            if (y + 2 > this.ground - 3) break;
            for (let col = 0; col < b.columns; col++) {
                const lit = b.windows[row * b.columns + col];
                if (colors.lit) {
                    ctx.fillStyle = lit < colors.lit ? colors.windows[lit < colors.lit / 3 ? 1 : 0] : colors.unlit;
                } else {
                    ctx.fillStyle = (row + col + Math.floor(lit * 3)) % 4 === 0 ? colors.unlit : colors.windows[lit < 0.5 ? 0 : 1];
                }
                ctx.fillRect(b.x + 2 + col * 3, y, 2, 2);
            }
        }
        if (b.hq) {
            // The entrance: glass doors at street level.
            ctx.fillStyle = colors.lit ? '#fce0a8' : '#a4e4fc';
            ctx.fillRect(b.x + Math.floor(b.w / 2) - 3, this.ground - 5, 6, 5);
        }
    }

    /** Pavement with lamps, the road with its centre line, and the cars. */
    drawStreet(colors, now) {
        const { ctx, w, h } = this;
        const g = this.ground;
        ctx.fillStyle = '#8a8aa0';
        ctx.fillRect(0, g, w, 1);
        ctx.fillStyle = '#5c5c70';
        ctx.fillRect(0, g + 1, w, 2);
        ctx.fillStyle = colors.road;
        ctx.fillRect(0, g + 3, w, h - g - 3);
        ctx.fillStyle = '#f8b800';
        for (let x = 0; x < w; x += 8) ctx.fillRect(x, g + 9, 4, 1);

        for (const x of this.lamps) {
            ctx.fillStyle = '#2c2c38';
            ctx.fillRect(x, g - 9, 1, 9);
            ctx.fillRect(x, g - 9, 3, 1);
            ctx.fillStyle = colors.glow ? '#fce0a8' : '#bcbcbc';
            ctx.fillRect(x + 2, g - 8, 2, 1);
            if (colors.glow) {
                ctx.fillStyle = '#fce0a822';
                ctx.fillRect(x, g - 7, 6, 7);
            }
        }

        for (const car of this.cars) {
            const span = w + 30;
            const travelled = (car.at * span + now * car.speed) % span;
            const right = car.lane === 1; // the lower lane drives right, the upper one left
            const x = Math.floor(right ? travelled - 15 : w + 15 - travelled);
            const y = car.lane === 1 ? g + 11 : g + 5;
            this.drawCar(x, y, car, right, colors);
        }
    }

    /** A small car, 8 (or a 12-pixel van) by 4, with wheels, a window, and lights at dusk and night. */
    drawCar(x, y, car, right, colors) {
        const { ctx } = this;
        const len = car.long ? 12 : 8;
        ctx.fillStyle = car.color;
        ctx.fillRect(x, y + 1, len, 2);
        ctx.fillRect(x + (right ? 1 : 2), y, len - 3, 1);
        ctx.fillStyle = '#a4e4fc';
        ctx.fillRect(x + (right ? len - 4 : 2), y, 2, 1);
        ctx.fillStyle = '#000';
        ctx.fillRect(x + 1, y + 3, 2, 1);
        ctx.fillRect(x + len - 3, y + 3, 2, 1);
        if (colors.glow) {
            ctx.fillStyle = '#fce0a8';
            ctx.fillRect(right ? x + len : x - 1, y + 1, 1, 1);
            ctx.fillStyle = '#e40058';
            ctx.fillRect(right ? x - 1 : x + len, y + 1, 1, 1);
        }
    }
}
