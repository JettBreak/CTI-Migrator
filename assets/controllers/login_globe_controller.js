import { Controller } from '@hotwired/stimulus';
import { geoDistance, geoEquirectangular, geoGraticule10, geoInterpolate, geoOrthographic, geoPath } from 'd3-geo';
import { feature } from 'topojson-client';
import { Starfield } from '../starfield.js';

/* stimulusFetch: 'lazy' */

/*
 * Draws the login page's outer-space scene: twinkling stars, frequent shooting stars and a
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

export default class extends Controller {
    static values = { land: String };

    connect() {
        this.ctx = this.element.getContext('2d');
        this.projection = geoOrthographic().clipAngle(90);
        this.path = geoPath(this.projection, this.ctx);
        this.graticule = geoGraticule10();
        this.arcs = LINKS.map(([a, b]) => geoInterpolate(HUBS[a], HUBS[b]));
        this.dots = [];
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

    draw() {
        const { ctx, width: w, height: h } = this;
        const now = this.still ? 0 : performance.now() / 1000;
        ctx.clearRect(0, 0, w, h);
        this.starfield.drawStars(ctx, w, h, now);
        if (!this.still) this.starfield.drawMeteors(ctx, w, h, now);
        this.drawGlobe(now);
    }

    drawGlobe(now) {
        const { ctx, width: w, height: h } = this;
        const radius = Math.min(h * 0.4, w * 0.2);
        const cx = w * 0.75, cy = h * 0.5;
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
