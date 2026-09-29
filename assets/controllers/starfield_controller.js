import { Controller } from '@hotwired/stimulus';
import { Starfield } from '../starfield.js';

/*
 * Twinkling stars and the odd shooting star on a canvas behind the sidebar; the nebula glow is the
 * sidebar's CSS background.
 *
 * The canvas is data-turbo-permanent (with an id), so Turbo keeps it across visits and leaves it alone
 * on morphing refreshes, which would otherwise reset the width/height set here and stretch the stars;
 * draw() also re-sizes the canvas if anything else resets it. The stars are seeded, so a full page
 * load shows the same sky. The animation pauses while the tab is hidden and is a still frame for
 * users who prefer reduced motion.
 *
 * The same controller draws the faint sky behind the page content in the dark theme (base.html.twig):
 * dark-only runs it only while <html data-theme="dark"> (the theme switch), so it costs nothing in light.
 *
 * <canvas id="sidebar-stars" data-controller="starfield" data-turbo-permanent aria-hidden="true"></canvas>
 * <canvas id="page-stars" data-controller="starfield" data-starfield-dark-only-value="true"
 *         data-starfield-meteors-value='{"gap": [4, 9], "max": 1}' data-turbo-permanent aria-hidden="true"></canvas>
 */
export default class extends Controller {
    static values = {
        count: { type: Number, default: 90 },
        seed: { type: Number, default: 20260928 },
        // Shooting stars: overrides of Starfield's defaults (see ../starfield.js); these suit the narrow sidebar.
        meteors: { type: Object, default: { gap: [2.5, 6], max: 2, area: [0.4, 1.1, 0, 0.8], distance: [120, 220], tail: [40, 90], life: [0.7, 1.2] } },
        darkOnly: Boolean,
    };

    connect() {
        this.ctx = this.element.getContext('2d');
        this.starfield = new Starfield({ count: this.countValue, seed: this.seedValue, meteors: this.meteorsValue });
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.onResize = () => { if (this.frame || this.still) { this.resize(); this.draw(); } };
        this.onVisibilityChange = () => this.sync();
        window.addEventListener('resize', this.onResize);
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        if (this.darkOnlyValue) {
            this.themeObserver = new MutationObserver(() => this.sync());
            this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        }

        this.sync();
    }

    disconnect() {
        this.stop();
        this.themeObserver?.disconnect();
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    /** Runs while the tab is visible (and, when dark-only, while the theme is dark); otherwise stops. */
    sync() {
        const shown = !this.darkOnlyValue || document.documentElement.dataset.theme === 'dark';
        if (shown && !document.hidden) {
            this.resize();
            this.start();
        } else {
            this.stop();
        }
    }

    start() {
        if (this.still) { this.draw(); return; }
        if (this.frame) return;
        const tick = () => { this.draw(); this.frame = requestAnimationFrame(tick); };
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
        this.element.width = Math.round(this.width * ratio);
        this.element.height = Math.round(this.height * ratio);
        this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    draw() {
        if (this.element.width !== Math.round(this.element.clientWidth * (window.devicePixelRatio || 1))
            || this.element.height !== Math.round(this.element.clientHeight * (window.devicePixelRatio || 1))) {
            this.resize();
        }
        const { ctx, width: w, height: h } = this;
        const now = this.still ? 0 : performance.now() / 1000;
        ctx.clearRect(0, 0, w, h);
        this.starfield.drawStars(ctx, w, h, now);
        if (!this.still) this.starfield.drawMeteors(ctx, w, h, now);
    }
}
