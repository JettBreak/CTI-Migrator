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
 * On the sign-in page (zoomable value) the mouse wheel zooms out, slowly, from the Earth to the whole
 * solar system in the free space beside the form, and back in. The wheel keeps scrolling the page instead
 * where the page is taller than the window, and does nothing while the globe is docked in the corner.
 *
 * With the hud value the hubs on the globe also pulse, and the marker value ([lon, lat], the app's timezone)
 * shows as an amber beacon labelled with the marker-label value (see drawHud()). Both fade out as the view
 * zooms out, and are left off when docked.
 *
 * The sun value is an image URL (the company logo) drawn in place of the Sun; without it, or until it has
 * loaded, the Sun is drawn as a glowing ball.
 *
 * <canvas data-controller="login-globe" data-login-globe-land-value="/assets/data/land-110m.json"
 *         data-login-globe-zoomable-value="true" data-login-globe-hud-value="true"
 *         data-login-globe-marker-value="[120.97,14.59]" data-login-globe-marker-label-value="MANILA"></canvas>
 */
const HUBS = [[121, 14.6], [103.8, 1.35], [139.7, 35.7], [-122.4, 37.8], [-0.1, 51.5], [55.3, 25.2], [151.2, -33.9], [114.2, 22.3]];
const LINKS = [[0, 1], [0, 2], [0, 3], [0, 7], [1, 5], [5, 4], [2, 3], [0, 6]];
const COLORS = { ring: 'rgba(143,192,255,.5)', grid: 'rgba(143,192,255,.14)', dot: 'rgba(170,205,255,.8)', arc: '#f59a23' };
const HORIZON = Math.PI / 2 - 0.02;
/** The moon's dark "seas" (lon, lat, radius in degrees): soft darker grey patches, as seen from Earth. */
const MARIA = [[-30, 25, 26], [10, 18, 16], [30, 5, 14], [-45, -8, 18], [55, 12, 12], [-10, -25, 13], [160, 20, 20], [-140, -10, 22]];
/** The larger craters (lon, lat, radius in degrees); many small ones are added at random (seeded, so always the same). */
const CRATERS = [[-11, -43, 6], [-20, 10, 5], [-38, 8, 4], [25, -30, 7], [70, -20, 5], [-80, 30, 6], [120, -40, 8], [-170, 35, 5], [95, 45, 4]];
/**
 * The solar system for the zoomed-out view, in "world" units where the Earth's radius is 1 (so with no
 * zoom one unit is the globe's radius in pixels). Distances and sizes are stylised, not to scale, so it
 * all fits beside the form: orbit radius, planet radius, colour, and where on its orbit each one starts.
 */
const SUN_RADIUS = 7;
const PLANETS = [
    { orbit: 24, size: 0.45, color: '#b9b1a6', phase: 2.1 },
    { orbit: 38, size: 0.9, color: '#e8c98f', phase: 4.0 },
    { orbit: 58, size: 1, color: '#4f8ef7', phase: 0.6, earth: true },
    { orbit: 82, size: 0.6, color: '#d2694a', phase: 5.2 },
    { orbit: 132, size: 4.2, color: '#d9b48c', phase: 3.1 },
    { orbit: 180, size: 3.6, color: '#e4cd95', phase: 1.3, ring: true },
    { orbit: 232, size: 2.2, color: '#9fd9e3', phase: 4.6 },
    { orbit: 280, size: 2.1, color: '#5b82e8', phase: 2.7 },
];
const EARTH = PLANETS.find((p) => p.earth);
/** Orbits are seen from slightly above: squashed vertically and tilted a little, like the moon's. */
const ORBIT_SQUASH = 0.3;
const ORBIT_TILT = -8 * Math.PI / 180;
const clamp = (v, lo = 0, hi = 1) => Math.min(hi, Math.max(lo, v));

/** $count small craters spread over the sphere, from a fixed seed so the moon always looks the same. */
function smallCraters(count) {
    let seed = 1969;
    const random = () => (seed = (seed * 16807) % 2147483647) / 2147483647;

    return Array.from({ length: count }, () => [random() * 360 - 180, Math.asin(random() * 2 - 1) * 180 / Math.PI, 1.2 + random() * 3]);
}

export default class extends Controller {
    static values = { land: String, zoomable: Boolean, hud: Boolean, marker: Array, markerLabel: String, sun: String };

    connect() {
        this.ctx = this.element.getContext('2d');
        this.projection = geoOrthographic().clipAngle(90);
        this.path = geoPath(this.projection, this.ctx);
        this.graticule = geoGraticule10();
        this.arcs = LINKS.map(([a, b]) => geoInterpolate(HUBS[a], HUBS[b]));
        this.dots = [];
        this.moonProjection = geoOrthographic().clipAngle(90);
        this.maria = MARIA.map(([lon, lat, r]) => geoCircle().center([lon, lat]).radius(r)());
        this.craters = [...CRATERS, ...smallCraters(60)].map(([lon, lat, r]) => geoCircle().center([lon, lat]).radius(r)());
        this.starfield = new Starfield({ count: 420 });
        this.rotation = -100;
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.zoom = 0; // 0: the Earth; 1: the whole solar system
        this.zoomTarget = 0;
        this.hint = document.querySelector('.zoom-hint');
        if (this.sunValue) {
            this.sunImage = new Image();
            this.sunImage.src = this.sunValue;
        }

        this.onResize = () => { this.resize(); this.draw(); };
        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.start());
        window.addEventListener('resize', this.onResize);
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        if (this.zoomableValue) {
            this.onWheel = this.wheel.bind(this);
            window.addEventListener('wheel', this.onWheel, { passive: false });
        }

        this.resize();
        this.loadLand();
        this.start();
    }

    disconnect() {
        this.stop();
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        if (this.onWheel) window.removeEventListener('wheel', this.onWheel);
    }

    /** Scrolling down zooms out towards the solar system, scrolling up zooms back in to the Earth. */
    wheel(event) {
        const body = document.body;
        // A page taller than the window keeps its normal scrolling; a docked globe does not zoom.
        if (this.globe?.docked || body.scrollHeight > body.clientHeight + 1) return;
        event.preventDefault();
        const pixels = event.deltaY * (event.deltaMode === 1 ? 40 : event.deltaMode === 2 ? 800 : 1);
        this.zoomTarget = clamp(this.zoomTarget + pixels / 2400);
        this.hint?.classList.add('is-used');
        if (this.still) { this.zoom = this.zoomTarget; this.draw(); }
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

    /** Animates at the display's frame rate; the globe turns 4.8 degrees a second, whatever that rate is. */
    start() {
        if (this.still) { this.draw(); return; }
        if (this.frame) return;
        let last = null;
        const tick = (time) => {
            this.frame = requestAnimationFrame(tick);
            this.rotation += 4.8 * Math.min(0.1, last === null ? 0 : (time - last) / 1000);
            last = time;
            this.draw();
        };
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
     * Sizes and places the globe. Its usual place is on the right, between the header and the safeguards
     * row (.login-safeguards, when shown), level with the form. Where that would run behind the heading or
     * the form (see contentRight()), it is "docked" instead: a large globe centred on the bottom-right
     * corner of the screen, so only its top-left quarter shows, dimmed and without the moon.
     *
     * The globe glides to its place rather than jumping: it zooms into the corner when the page opens
     * docked, and moves between the two places when the window is resized (not with reduced motion).
     * Measured every frame (a few cheap reads): the layout settles only after fonts and the Tailwind CDN
     * styles load, and the lockout form changes height as its options change.
     */
    placeGlobe(now) {
        const top = document.querySelector('header')?.getBoundingClientRect().bottom ?? 0;
        const row = document.querySelector('.login-safeguards');
        const bottom = row && row.offsetParent ? row.getBoundingClientRect().top : this.height;
        const gap = 28; // breathing room around the globe
        const contentRight = this.contentRight();
        this.lastContentRight = contentRight;
        const radius = Math.min(this.width * 0.13, (bottom - top - 2 * gap) / 2);
        const cx = this.width * 0.74;
        // The glow reaches 1.1 × the radius: it must stay clear of the content.
        const docked = radius < 60 || cx - radius * 1.1 < contentRight + gap;
        const target = docked
            ? { radius: Math.max(120, Math.min(this.width, this.height) * 0.5), cx: this.width, cy: this.height }
            : { radius, cx, cy: (top + bottom) / 2 };

        if (!this.view) {
            // First frame: a docked globe starts small in the corner and zooms in.
            this.view = docked && !this.still ? { ...target, radius: target.radius * 0.2 } : { ...target };
        }
        const dt = Math.min(0.1, Math.max(0, now - (this.lastNow ?? now)));
        this.lastNow = now;
        const follow = this.still ? 1 : 1 - Math.exp(-dt * 3.5); // eases in over about a second
        for (const key of ['radius', 'cx', 'cy']) this.view[key] += (target[key] - this.view[key]) * follow;
        this.globe = { ...this.view, docked };

        // The moon's orbit is wide enough to leave the screen on the right (the moon flies out of view
        // and comes back), but its left end stays clear of the content.
        const { radius: r, cx: x } = this.globe;
        this.orbitWidth = Math.max(r * 1.72, Math.min((this.width - x) * 1.3, (x - contentRight - 60) / Math.cos(14 * Math.PI / 180)));
    }

    /**
     * The right edge of what the page shows on the left: the heading's text (measured as text, not as
     * its full-width box) and the form column.
     */
    contentRight() {
        let right = 0;
        const heading = document.querySelector('main h1');
        if (heading) {
            const range = document.createRange();
            range.selectNodeContents(heading);
            right = range.getBoundingClientRect().right;
        }
        document.querySelectorAll('main form, main .max-w-sm, main .max-w-md').forEach((element) => {
            right = Math.max(right, element.getBoundingClientRect().right);
        });

        return right || this.width * 0.35;
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
        this.placeGlobe(now);
        if (this.globe.docked) {
            // In the corner, behind whatever text reaches it: dimmed, and without the moon.
            this.zoom = this.zoomTarget = 0;
            this.turnFrom = undefined;
            ctx.save();
            ctx.globalAlpha = 0.6;
            this.drawGlobe(now);
            ctx.restore();
            return;
        }
        this.stepZoom(now);
        if (this.zoom > 0.001) {
            this.drawBesideContent(() => this.drawSolarSystem(now));
            return;
        }
        this.turnFrom = undefined; // back on the Earth: the next zoom out takes its turn afresh
        this.drawEarthAndMoon(now);
    }

    /**
     * Runs $paint on an offscreen layer, then copies it on screen faded to translucent over the heading and
     * the form: while zooming, the orbits are wider than the free space and the Sun and planets pass behind
     * the content, where they should show through faintly rather than over the text.
     */
    drawBesideContent(paint) {
        const main = this.ctx;
        const ratio = window.devicePixelRatio || 1;
        const canvas = this.layerCanvas ??= document.createElement('canvas');
        if (canvas.width !== this.element.width || canvas.height !== this.element.height) {
            canvas.width = this.element.width;
            canvas.height = this.element.height;
        }
        const layer = canvas.getContext('2d');
        layer.setTransform(ratio, 0, 0, ratio, 0, 0);
        layer.clearRect(0, 0, this.width, this.height);

        this.ctx = layer;
        this.path.context(layer);
        try {
            paint();
        } finally {
            this.ctx = main;
            this.path.context(main);
        }

        // Keep 25% of the layer over the content, easing up to all of it just beyond the content's right edge.
        const edge = this.lastContentRight ?? this.width * 0.35;
        const mask = layer.createLinearGradient(edge, 0, edge + 80, 0);
        mask.addColorStop(0, 'rgba(0,0,0,.25)');
        mask.addColorStop(1, 'rgba(0,0,0,1)');
        layer.save();
        layer.globalCompositeOperation = 'destination-in';
        layer.fillStyle = mask;
        layer.fillRect(0, 0, this.width, this.height);
        layer.restore();

        main.drawImage(canvas, 0, 0, this.width, this.height);
    }

    /** The globe with the moon going round it; on the far side the moon passes behind the globe. */
    drawEarthAndMoon(now, moonAlpha = 1) {
        const { ctx } = this;
        // One orbit a minute.
        this.moon = this.orbitPoint(this.still ? 1.1 : 1.1 + now * (2 * Math.PI / 60));
        ctx.save();
        ctx.globalAlpha = moonAlpha;
        this.drawOrbit(true);
        if (this.moon.behind) this.drawMoon(now);
        ctx.restore();
        this.drawGlobe(now);
        // Gone once the zoom has barely started, so it never hangs around a shrinking Earth.
        if (this.hudValue) this.drawHud(now, moonAlpha * clamp(1 - this.zoom * 5));
        ctx.save();
        ctx.globalAlpha = moonAlpha;
        this.drawOrbit(false);
        if (!this.moon.behind) this.drawMoon(now);
        ctx.restore();
    }

    /** Eases the zoom towards where the wheel left it: slowly, over a couple of seconds. */
    stepZoom(now) {
        const dt = Math.min(0.1, Math.max(0, now - (this.lastZoomAt ?? now)));
        this.lastZoomAt = now;
        this.zoom += (this.zoomTarget - this.zoom) * (this.still ? 1 : 1 - Math.exp(-dt * 1.6));
        if (Math.abs(this.zoomTarget - this.zoom) < 0.0005) this.zoom = this.zoomTarget;
    }

    /** Where on its orbit a planet is (radians): one Earth year every two minutes, the others slower further out. */
    planetAngle(planet, now) {
        return planet.phase + (this.still ? 0 : now * (2 * Math.PI / 120) * Math.pow(EARTH.orbit / planet.orbit, 1.5));
    }

    /**
     * Where a planet is, in world units around the Sun, and whether it is on the far side of its orbit.
     * $turn rotates the whole system (see drawSolarSystem()).
     */
    planetAt(planet, now, turn = 0) {
        const angle = this.planetAngle(planet, now) + turn;

        return { ...this.orbitAt(planet.orbit, angle), far: Math.sin(angle) < 0 };
    }

    orbitAt(radius, angle) {
        const x = radius * Math.cos(angle), y = radius * Math.sin(angle) * ORBIT_SQUASH;

        return { x: x * Math.cos(ORBIT_TILT) - y * Math.sin(ORBIT_TILT), y: x * Math.sin(ORBIT_TILT) + y * Math.cos(ORBIT_TILT) };
    }

    /**
     * The zoomed-out view. The camera's scale falls exponentially from "one world unit = the globe's radius"
     * to "the outermost orbit fills the free space beside the form", while it slides from the Earth to the
     * Sun and from the globe's place to the middle of the free space. The Earth drifts out to its true place
     * on its orbit only as the zoom ends, so it stays in view the whole way.
     */
    drawSolarSystem(now) {
        const { ctx } = this;
        const z = this.zoom;
        const ease = z * z * (3 - 2 * z);
        const base = this.globe;
        const baseOrbit = this.orbitWidth; // the moon's orbit with no zoom, as placeGlobe() set it this frame
        const contentRight = this.lastContentRight ?? this.width * 0.35;
        const fit = Math.max(120, Math.min((this.width - contentRight) / 2 - 40, this.height * 1.1));
        const s0 = base.radius, s1 = fit / PLANETS.at(-1).orbit;
        const scale = Math.exp(Math.log(s0) + (Math.log(s1) - Math.log(s0)) * z);
        const anchor = { x: base.cx + ((contentRight + this.width) / 2 - base.cx) * ease, y: base.cy };
        // Start with the Earth on the left of the Sun, so the Sun comes into view on the right, away from the
        // form; the system turns back to the planets' real places as the zoom completes. The turn is taken
        // once, as the zoom leaves the Earth: worked out anew every frame, it wraps from -180° to +180° each
        // time the Earth passes the far end of its orbit, and the whole system jumped mid-zoom.
        if (this.turnFrom === undefined) {
            const toLeft = Math.PI - this.planetAngle(EARTH, now);
            this.turnFrom = Math.atan2(Math.sin(toLeft), Math.cos(toLeft));
        }
        const turn = this.turnFrom * (1 - ease);
        const earth = this.planetAt(EARTH, now, turn);
        const follow = Math.min(1, s1 * Math.pow(z, 1.5) / scale); // 0: centred on the Earth; 1: on the Sun
        const camera = { x: earth.x * (1 - follow), y: earth.y * (1 - follow) };
        const toScreen = (p) => ({ x: anchor.x + (p.x - camera.x) * scale, y: anchor.y + (p.y - camera.y) * scale });
        const reveal = clamp((z - 0.12) / 0.4); // the rest of the solar system fades in

        if (reveal > 0) {
            ctx.save();
            ctx.setLineDash([3, 6]);
            ctx.lineWidth = 1;
            ctx.strokeStyle = `rgba(156,198,255,${0.22 * reveal})`;
            for (const planet of PLANETS) {
                ctx.beginPath();
                for (let i = 0; i <= 120; ++i) {
                    const { x, y } = toScreen(this.orbitAt(planet.orbit, (i / 120) * 2 * Math.PI));
                    i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
                }
                ctx.stroke();
            }
            ctx.restore();
        }

        const others = PLANETS.filter((p) => !p.earth).map((p) => ({ planet: p, ...this.planetAt(p, now, turn) }));
        const drawPlanets = (far) => {
            if (reveal <= 0) return;
            ctx.save();
            ctx.globalAlpha = reveal;
            for (const p of others.filter((o) => o.far === far)) {
                this.drawPlanet(p.planet, toScreen(p), Math.min(80, Math.max(1.8, p.planet.size * scale)));
            }
            ctx.restore();
        };
        drawPlanets(true);
        this.drawSun(toScreen({ x: 0, y: 0 }), Math.min(500, Math.max(9, SUN_RADIUS * scale)));
        drawPlanets(false);

        // The Earth: the full globe (its moon fading out) while it is big enough, then a blue dot.
        const at = toScreen(earth);
        const radius = scale * EARTH.size;
        if (radius >= 14) {
            this.globe = { ...base, radius, cx: at.x, cy: at.y };
            this.orbitWidth = baseOrbit * (scale / s0);
            this.drawEarthAndMoon(now, clamp((radius - 14) / 50));
            this.globe = base;
            this.orbitWidth = baseOrbit;
        } else {
            this.drawPlanet(EARTH, at, Math.max(2.4, radius));
        }
    }

    drawSun({ x, y }, r) {
        const { ctx } = this;
        if (x + r * 4 < 0 || x - r * 4 > this.width || y + r * 4 < 0 || y - r * 4 > this.height) return;
        const corona = ctx.createRadialGradient(x, y, r * 0.8, x, y, r * 4);
        corona.addColorStop(0, 'rgba(255,190,90,.42)');
        corona.addColorStop(0.4, 'rgba(245,154,35,.14)');
        corona.addColorStop(1, 'rgba(245,154,35,0)');
        ctx.fillStyle = corona;
        ctx.beginPath(); ctx.arc(x, y, r * 4, 0, 2 * Math.PI); ctx.fill();
        // The company logo (the sun value) in place of the Sun's body, once it has loaded.
        if (this.sunImage?.complete && this.sunImage.naturalWidth) {
            ctx.save();
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(this.sunImage, x - r, y - r, r * 2, r * 2);
            ctx.restore();
            return;
        }
        const body = ctx.createRadialGradient(x - r * 0.3, y - r * 0.3, r * 0.1, x, y, r);
        body.addColorStop(0, '#fffbe8');
        body.addColorStop(0.5, '#ffd36b');
        body.addColorStop(1, '#f59a23');
        ctx.fillStyle = body;
        ctx.beginPath(); ctx.arc(x, y, r, 0, 2 * Math.PI); ctx.fill();
    }

    /** A planet as a small sphere lit from the upper left, with Saturn's ring and the Earth's blue glow. */
    drawPlanet(planet, { x, y }, r) {
        const { ctx } = this;
        if (planet.ring) {
            ctx.save();
            ctx.strokeStyle = 'rgba(228,205,149,.55)';
            ctx.lineWidth = Math.max(1, r * 0.28);
            ctx.beginPath(); ctx.ellipse(x, y, r * 2.1, r * 0.62, ORBIT_TILT, 0, 2 * Math.PI); ctx.stroke();
            ctx.restore();
        }
        if (r < 3) {
            ctx.fillStyle = planet.color;
        } else {
            const body = ctx.createRadialGradient(x - r * 0.4, y - r * 0.4, r * 0.1, x, y, r);
            body.addColorStop(0, '#ffffff');
            body.addColorStop(0.25, planet.color);
            body.addColorStop(1, 'rgba(8,12,26,.9)');
            ctx.fillStyle = body;
        }
        ctx.beginPath(); ctx.arc(x, y, r, 0, 2 * Math.PI); ctx.fill();
        if (planet.earth) {
            const glow = ctx.createRadialGradient(x, y, r, x, y, r * 3);
            glow.addColorStop(0, 'rgba(92,155,255,.45)');
            glow.addColorStop(1, 'rgba(92,155,255,0)');
            ctx.fillStyle = glow;
            ctx.beginPath(); ctx.arc(x, y, r * 3, 0, 2 * Math.PI); ctx.fill();
        }
    }

    /**
     * The moon, drawn to look like the real thing: an opaque grey sphere lit from the upper left (like
     * the globe), darker at its edge, with soft grey seas and craters that turn very slowly with it.
     *
     * Its surface (blurred seas, some seventy craters) is the most expensive thing in the scene, and it
     * turns only 1.5 degrees a second, so it is drawn into an offscreen canvas and reused: redrawn when
     * it has turned another degree, or when its size has changed by more than a few pixels (its size
     * changes along its orbit and while zooming; the cached image is scaled for the rest).
     */
    drawMoon(now) {
        const { ctx } = this;
        const { radius: r, x: cx, y: cy } = this.moon;
        if (r < 0.5) return;

        const halo = ctx.createRadialGradient(cx, cy, r * 0.9, cx, cy, r * 2.2);
        halo.addColorStop(0, 'rgba(235,238,245,.22)');
        halo.addColorStop(1, 'rgba(235,238,245,0)');
        ctx.fillStyle = halo;
        ctx.beginPath(); ctx.arc(cx, cy, r * 2.2, 0, 2 * Math.PI); ctx.fill();

        const rotation = Math.round(40 - now * 1.5); // degrees; a slow turn, so the craters move like on a sphere
        const size = Math.max(8, Math.ceil(r / 6) * 6); // drawn a little larger, then scaled down
        const ratio = window.devicePixelRatio || 1;
        if (!this.moonCache || this.moonCache.rotation !== rotation || this.moonCache.size !== size || this.moonCache.ratio !== ratio) {
            this.moonCache = { rotation, size, ratio, canvas: this.renderMoon(size, rotation, ratio) };
        }
        ctx.drawImage(this.moonCache.canvas, cx - r, cy - r, 2 * r, 2 * r);
    }

    /** The moon's surface, $r pixels in radius (times the pixel ratio), on one reused offscreen canvas. */
    renderMoon(r, rotation, ratio) {
        const canvas = this.moonCanvas ??= document.createElement('canvas');
        canvas.width = canvas.height = Math.ceil(2 * r * ratio);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, 2 * r, 2 * r);
        const cx = r, cy = r;
        const projection = this.moonProjection.scale(r).translate([cx, cy]).rotate([rotation, -18]);
        const path = geoPath(projection, ctx);

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
            ctx.beginPath(); path(mare); ctx.fill();
        }
        ctx.filter = 'none';

        // Craters: a shallow darker hollow with a lighter rim.
        ctx.lineWidth = Math.max(0.5, r / 60);
        for (const crater of this.craters) {
            ctx.beginPath(); path(crater);
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

        return canvas;
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
        const alpha = ctx.globalAlpha; // lower when the globe is docked and dimmed
        for (const arc of this.arcs) {
            ctx.globalAlpha = alpha * 0.75;
            ctx.beginPath();
            this.path({ type: 'LineString', coordinates: Array.from({ length: 21 }, (_, i) => arc(i / 20)) });
            ctx.stroke();
            ctx.globalAlpha = alpha;
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

    /**
     * The console-style overlay, drawn right after drawGlobe() (whose projection it reuses): a ripple from each
     * hub on the near side, staggered so they do not beat together, and the timezone marker (see drawMarker()).
     */
    drawHud(now, alpha) {
        if (alpha < 0.01) return;
        const { ctx } = this;
        const center = [-this.rotation, 12];
        ctx.save();
        ctx.lineWidth = 1.2;
        const base = ctx.globalAlpha * alpha;
        HUBS.forEach((hub, i) => {
            if (geoDistance(hub, center) >= HORIZON) return;
            const [x, y] = this.projection(hub);
            const phase = this.still ? 0.35 : (now / 2.4 + i * 0.37) % 1;
            ctx.globalAlpha = base * (1 - phase) * 0.9;
            ctx.strokeStyle = '#8fc0ff';
            ctx.beginPath(); ctx.arc(x, y, 3.5 + phase * 11, 0, 2 * Math.PI); ctx.stroke();
        });
        if (this.markerValue.length === 2 && geoDistance(this.markerValue, center) < HORIZON) {
            ctx.globalAlpha = base;
            this.drawMarker(now, this.projection(this.markerValue));
        }
        ctx.restore();
    }

    /**
     * The app's timezone on the globe (the marker value, [lon, lat]): an amber beacon with a soft glow and two
     * ripples, larger than the hubs so it stands out among them.
     */
    drawMarker(now, [x, y]) {
        const { ctx } = this;
        const base = ctx.globalAlpha;
        const glow = ctx.createRadialGradient(x, y, 0, x, y, 16);
        glow.addColorStop(0, 'rgba(245,154,35,.55)');
        glow.addColorStop(1, 'rgba(245,154,35,0)');
        ctx.fillStyle = glow;
        ctx.beginPath(); ctx.arc(x, y, 16, 0, 2 * Math.PI); ctx.fill();

        ctx.strokeStyle = COLORS.arc;
        ctx.lineWidth = 1.5;
        for (const offset of [0, 0.5]) {
            const phase = this.still ? 0.3 + offset : (now / 1.8 + offset) % 1;
            ctx.globalAlpha = base * (1 - phase);
            ctx.beginPath(); ctx.arc(x, y, 5 + phase * 18, 0, 2 * Math.PI); ctx.stroke();
        }
        ctx.globalAlpha = base;

        ctx.fillStyle = COLORS.arc;
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(x, y, 5, 0, 2 * Math.PI); ctx.fill(); ctx.stroke();

        if (this.markerLabelValue) this.drawMarkerLabel(x, y, this.markerLabelValue);
    }

    /** The marker's city name, just right of it on a faint dark backing so it reads over the land dots. */
    drawMarkerLabel(x, y, text) {
        const { ctx } = this;
        ctx.font = '600 11px "JetBrains Mono", ui-monospace, monospace';
        const w = ctx.measureText(text).width + 12, h = 18;
        const left = x + 11, top = y - h / 2;
        ctx.fillStyle = 'rgba(6,14,34,.72)';
        ctx.beginPath(); ctx.roundRect(left, top, w, h, 3); ctx.fill();
        ctx.fillStyle = '#f5c98a';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, left + 6, y + 0.5);
    }
}
