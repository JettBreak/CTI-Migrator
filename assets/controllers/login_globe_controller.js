import { Controller } from '@hotwired/stimulus';
import { geoCircle, geoDistance, geoEquirectangular, geoGraticule10, geoInterpolate, geoOrthographic, geoPath } from 'd3-geo';
import { feature } from 'topojson-client';
import { Starfield } from '../starfield.js';

/* stimulusFetch: 'lazy' */

/*
 * Draws the login page's outer-space scene: twinkling stars, frequent shooting stars, a moon and a
 * slowly rotating dotted globe with data arcs. The nebula glows come from the body's CSS
 * background.
 *
 * The animation pauses while the tab is hidden, and draws a single still frame when the
 * user prefers reduced motion. If the map data fails to load the globe is drawn without
 * continents; if this controller never runs, the body's CSS background is the fallback.
 *
 * Dispatches "login-globe:ready" once the continents are drawn (or failed to load), so the page
 * loader can keep the page covered until then.
 *
 * <canvas data-controller="login-globe" data-login-globe-land-value="/assets/data/land-110m.json"></canvas>
 */
const HUBS = [[121, 14.6], [103.8, 1.35], [139.7, 35.7], [-122.4, 37.8], [-0.1, 51.5], [55.3, 25.2], [151.2, -33.9], [114.2, 22.3]];
const LINKS = [[0, 1], [0, 2], [0, 3], [0, 7], [1, 5], [5, 4], [2, 3], [0, 6]];
const COLORS = { ring: 'rgba(143,192,255,.5)', grid: 'rgba(143,192,255,.14)', dot: 'rgba(170,205,255,.8)', arc: '#f59a23' };
const HORIZON = Math.PI / 2 - 0.02;
/** The moon's dark "seas" (lon, lat, radius in degrees): soft darker grey patches, as seen from Earth. */
const MARIA = [[-30, 25, 26], [10, 18, 16], [30, 5, 14], [-45, -8, 18], [55, 12, 12], [-10, -25, 13], [160, 20, 20], [-140, -10, 22]];
/** The larger craters (lon, lat, radius in degrees); many small ones are added at random (seeded, so always the same). */
const CRATERS = [[-11, -43, 6], [-20, 10, 5], [-38, 8, 4], [25, -30, 7], [70, -20, 5], [-80, 30, 6], [120, -40, 8], [-170, 35, 5], [95, 45, 4]];

/** $count small craters spread over the sphere, from a fixed seed so the moon always looks the same. */
function smallCraters(count) {
    let seed = 1969;
    const random = () => (seed = (seed * 16807) % 2147483647) / 2147483647;

    return Array.from({ length: count }, () => [random() * 360 - 180, Math.asin(random() * 2 - 1) * 180 / Math.PI, 1.2 + random() * 3]);
}

export default class extends Controller {
    static values = { land: String };

    connect() {
        this.ctx = this.element.getContext('2d');
        this.projection = geoOrthographic().clipAngle(90);
        this.path = geoPath(this.projection, this.ctx);
        this.graticule = geoGraticule10();
        this.arcs = LINKS.map(([a, b]) => geoInterpolate(HUBS[a], HUBS[b]));
        this.dots = [];
        this.moonProjection = geoOrthographic().clipAngle(90);
        this.moonPath = geoPath(this.moonProjection, this.ctx);
        this.maria = MARIA.map(([lon, lat, r]) => geoCircle().center([lon, lat]).radius(r)());
        this.craters = [...CRATERS, ...smallCraters(60)].map(([lon, lat, r]) => geoCircle().center([lon, lat]).radius(r)());
        this.starfield = new Starfield({ count: 420 });
        this.rotation = -100;
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.onResize = () => { this.resize(); this.draw(); };
        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.start());
        window.addEventListener('resize', this.onResize);
        document.addEventListener('visibilitychange', this.onVisibilityChange);

        this.resize();
        this.loadLand();
        this.start();
    }

    disconnect() {
        this.stop();
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    async loadLand() {
        try {
            const world = await (await fetch(this.landValue)).json();
            const land = feature(world, world.objects.land);
            // Paint the land once on a small flat map and sample its pixels: thousands of times faster
            // than geoContains() per dot, which blocked the page for seconds.
            const map = document.createElement('canvas');
            map.width = 720;
            map.height = 360;
            const mapCtx = map.getContext('2d', { willReadFrequently: true });
            const flat = geoEquirectangular().scale(map.width / (2 * Math.PI)).translate([map.width / 2, map.height / 2]);
            mapCtx.beginPath();
            geoPath(flat, mapCtx)(land);
            mapCtx.fill();
            const pixels = mapCtx.getImageData(0, 0, map.width, map.height).data;
            for (let lat = -80; lat <= 80; lat += 2.4) {
                const step = 2.4 / Math.max(0.3, Math.cos(lat * Math.PI / 180));
                for (let lon = -180; lon < 180; lon += step) {
                    const [x, y] = flat([lon, lat]);
                    if (pixels[(Math.floor(y) * map.width + Math.floor(x)) * 4 + 3] > 127) this.dots.push([lon, lat]);
                }
            }
        } catch {
            // Keep the graticule-only globe.
        }
        if (this.still) this.draw();
        // Tell the page loader (waiting for "login-globe:ready") once a frame with the continents is on screen.
        requestAnimationFrame(() => requestAnimationFrame(() => this.dispatch('ready')));
    }

    start() {
        if (this.still) { this.draw(); return; }
        if (this.frame) return;
        const tick = () => { this.rotation += 0.08; this.draw(); this.frame = requestAnimationFrame(tick); };
        this.frame = requestAnimationFrame(tick);
    }

    stop() {
        cancelAnimationFrame(this.frame);
        this.frame = null;
    }

    resize() {
        const ratio = window.devicePixelRatio || 1;
        this.width = this.element.clientWidth;
        this.height = this.element.clientHeight;
        this.element.width = this.width * ratio;
        this.element.height = this.height * ratio;
        this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    /**
     * Sizes and places the globe in the free space on the right, between the header and the safeguards
     * row (.login-safeguards) along the bottom: centred in it (level with the sign-in form), never
     * running behind their text. Measured every frame
     * (two cheap reads): the layout settles only after fonts and the Tailwind CDN styles load.
     */
    placeGlobe() {
        const top = document.querySelector('header')?.getBoundingClientRect().bottom ?? 0;
        const bottom = document.querySelector('.login-safeguards')?.getBoundingClientRect().top ?? this.height;
        const gap = 28; // breathing room above the row and below the header
        const radius = Math.max(60, Math.min(this.width * 0.13, (bottom - top - 2 * gap) / 2));
        const cx = this.width * 0.74;
        this.globe = { radius, cx, cy: (top + bottom) / 2 };

        // The moon's orbit is wide enough to leave the screen on the right (the moon flies out of view
        // and comes back), but its left end stays clear of the sign-in form.
        const formRight = document.querySelector('main form')?.getBoundingClientRect().right ?? this.width * 0.35;
        this.orbitWidth = Math.max(radius * 1.72, Math.min((this.width - cx) * 1.3, (cx - formRight - 60) / Math.cos(14 * Math.PI / 180)));
    }

    /**
     * The moon's orbit around the globe: a wide tilted ellipse seen almost edge-on. Returns the moon's centre,
     * its size (a little larger on the near side), and whether it is on the far side, behind the globe.
     */
    orbitPoint(angle) {
        const { radius, cx, cy } = this.globe;
        const rx = this.orbitWidth, ry = rx * 0.2, tilt = -14 * Math.PI / 180;
        const x = rx * Math.cos(angle), y = ry * Math.sin(angle);
        const depth = Math.sin(angle); // > 0: near side (in front of the globe)

        return {
            x: cx + x * Math.cos(tilt) - y * Math.sin(tilt),
            y: cy + x * Math.sin(tilt) + y * Math.cos(tilt),
            radius: radius * (0.15 + 0.025 * depth),
            behind: depth < 0,
        };
    }

    /** Half of the dotted orbit ring: the far half (drawn before the globe, so it hides it) or the near half. */
    drawOrbit(far) {
        const { ctx } = this;
        ctx.save();
        ctx.setLineDash([3, 6]);
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(156,198,255,.24)';
        ctx.beginPath();
        for (let i = 0; i <= 64; ++i) {
            const angle = far ? Math.PI + (i / 64) * Math.PI : (i / 64) * Math.PI;
            const { x, y } = this.orbitPoint(angle);
            i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
        }
        ctx.stroke();
        ctx.restore();
    }

    draw() {
        const { ctx, width: w, height: h } = this;
        const now = this.still ? 0 : performance.now() / 1000;
        ctx.clearRect(0, 0, w, h);
        this.starfield.drawStars(ctx, w, h, now);
        if (!this.still) this.starfield.drawMeteors(ctx, w, h, now);
        this.placeGlobe();
        // One orbit a minute; on the far side the moon passes behind the globe.
        this.moon = this.orbitPoint(this.still ? 1.1 : 1.1 + now * (2 * Math.PI / 60));
        this.drawOrbit(true);
        if (this.moon.behind) this.drawMoon(now);
        this.drawGlobe(now);
        this.drawOrbit(false);
        if (!this.moon.behind) this.drawMoon(now);
    }

    /**
     * The moon, drawn to look like the real thing: an opaque grey sphere lit from the upper left (like
     * the globe), darker at its edge, with soft grey seas and craters that turn very slowly with it.
     */
    drawMoon(now) {
        const { ctx } = this;
        const { radius: r, x: cx, y: cy } = this.moon;
        const rotation = 40 - now * 1.5; // degrees; a slow turn, so the craters move like on a sphere
        this.moonProjection.scale(r).translate([cx, cy]).rotate([rotation, -18]);

        const halo = ctx.createRadialGradient(cx, cy, r * 0.9, cx, cy, r * 2.2);
        halo.addColorStop(0, 'rgba(235,238,245,.22)');
        halo.addColorStop(1, 'rgba(235,238,245,0)');
        ctx.fillStyle = halo;
        ctx.beginPath(); ctx.arc(cx, cy, r * 2.2, 0, 2 * Math.PI); ctx.fill();

        ctx.save();
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI); ctx.clip();

        const surface = ctx.createRadialGradient(cx - r * 0.35, cy - r * 0.35, r * 0.05, cx, cy, r * 1.05);
        surface.addColorStop(0, '#f2f0ea');
        surface.addColorStop(0.55, '#d3d0c8');
        surface.addColorStop(1, '#8f8c85');
        ctx.fillStyle = surface;
        ctx.fillRect(cx - r, cy - r, 2 * r, 2 * r);

        // Seas: soft-edged darker grey.
        ctx.filter = `blur(${Math.max(1, r / 16)}px)`;
        ctx.fillStyle = 'rgba(96,94,90,.42)';
        for (const mare of this.maria) {
            ctx.beginPath(); this.moonPath(mare); ctx.fill();
        }
        ctx.filter = 'none';

        // Craters: a shallow darker hollow with a lighter rim.
        ctx.lineWidth = Math.max(0.5, r / 60);
        for (const crater of this.craters) {
            ctx.beginPath(); this.moonPath(crater);
            ctx.fillStyle = 'rgba(88,86,82,.22)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,250,.28)';
            ctx.stroke();
        }

        // Light from the upper left: the lower-right side falls into shadow, and the edge darkens.
        const shadow = ctx.createRadialGradient(cx - r * 0.45, cy - r * 0.45, r * 0.3, cx - r * 0.1, cy - r * 0.1, r * 1.45);
        shadow.addColorStop(0, 'rgba(8,12,26,0)');
        shadow.addColorStop(0.7, 'rgba(8,12,26,.25)');
        shadow.addColorStop(1, 'rgba(8,12,26,.72)');
        ctx.fillStyle = shadow;
        ctx.fillRect(cx - r, cy - r, 2 * r, 2 * r);
        ctx.restore();
    }

    drawGlobe(now) {
        const { ctx, width: w, height: h } = this;
        const { radius, cx, cy } = this.globe;
        this.projection.scale(radius).translate([cx, cy]).rotate([this.rotation, -12]);
        const center = [-this.rotation, 12];
        const visible = (p) => geoDistance(p, center) < HORIZON;

        const atmosphere = ctx.createRadialGradient(cx, cy, radius * 0.9, cx, cy, radius * 1.35);
        atmosphere.addColorStop(0, 'rgba(92,155,255,.35)');
        atmosphere.addColorStop(1, 'rgba(92,155,255,0)');
        ctx.fillStyle = atmosphere;
        ctx.beginPath(); ctx.arc(cx, cy, radius * 1.35, 0, 2 * Math.PI); ctx.fill();
        const body = ctx.createRadialGradient(cx - radius * 0.35, cy - radius * 0.35, radius * 0.1, cx, cy, radius);
        body.addColorStop(0, '#1b3d78');
        body.addColorStop(1, '#081631');
        ctx.fillStyle = body;
        ctx.beginPath(); ctx.arc(cx, cy, radius, 0, 2 * Math.PI); ctx.fill();

        ctx.lineWidth = 1.5;
        ctx.strokeStyle = COLORS.ring;
        ctx.beginPath(); ctx.arc(cx, cy, radius, 0, 2 * Math.PI); ctx.stroke();
        ctx.lineWidth = 1;
        ctx.strokeStyle = COLORS.grid;
        ctx.beginPath(); this.path(this.graticule); ctx.stroke();

        ctx.fillStyle = COLORS.dot;
        for (const p of this.dots) {
            if (!visible(p)) continue;
            const [x, y] = this.projection(p);
            ctx.fillRect(x - 1, y - 1, 2, 2);
        }

        const t = this.still ? 0.5 : (now / 2.5) % 1;
        ctx.strokeStyle = COLORS.arc;
        ctx.fillStyle = COLORS.arc;
        ctx.lineWidth = 1.6;
        for (const arc of this.arcs) {
            ctx.globalAlpha = 0.75;
            ctx.beginPath();
            this.path({ type: 'LineString', coordinates: Array.from({ length: 21 }, (_, i) => arc(i / 20)) });
            ctx.stroke();
            ctx.globalAlpha = 1;
            const pulse = arc(t);
            if (visible(pulse)) {
                const [x, y] = this.projection(pulse);
                ctx.beginPath(); ctx.arc(x, y, 2.6, 0, 2 * Math.PI); ctx.fill();
            }
        }
        for (const hub of HUBS) {
            if (!visible(hub)) continue;
            const [x, y] = this.projection(hub);
            ctx.beginPath(); ctx.arc(x, y, 3.5, 0, 2 * Math.PI);
            ctx.fillStyle = '#fff'; ctx.fill(); ctx.stroke();
        }
    }
}
