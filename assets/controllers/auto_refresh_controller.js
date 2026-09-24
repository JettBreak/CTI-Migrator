import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * Reloads the current page every few seconds while this element is on screen.
 *
 * Unlike <meta http-equiv="refresh">, the timer is cleared as soon as the user navigates away
 * (Turbo swaps the page and this controller disconnects), so it never drags them back here.
 * It also pauses while the browser tab is hidden. Reloads use Turbo's morphing refresh, which
 * keeps the scroll position.
 *
 * <div data-controller="auto-refresh" data-auto-refresh-interval-value="5">…</div>
 */
export default class extends Controller {
    static values = { interval: { type: Number, default: 5 } };

    connect() {
        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.start());
        // A morphing refresh updates this element in place, so the controller stays connected and
        // connect() does not run again: re-arm the timer after every render, or it refreshes only once.
        this.onRender = () => this.start();
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        document.addEventListener('turbo:render', this.onRender);
        this.start();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        document.removeEventListener('turbo:render', this.onRender);
        this.stop();
    }

    start() {
        this.stop();
        if (!document.hidden) {
            this.timer = setTimeout(() => this.refresh(), this.intervalValue * 1000);
        }
    }

    stop() {
        clearTimeout(this.timer);
    }

    refresh() {
        // Only refresh the page this controller belongs to; never follow the user elsewhere.
        if (this.element.isConnected) {
            Turbo.visit(window.location.href, { action: 'replace' });
        }
    }
}
