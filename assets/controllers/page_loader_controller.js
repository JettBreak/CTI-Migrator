import { Controller } from '@hotwired/stimulus';
import { rocketSwipe, takeRocketSwipe } from '../rocket_swipe.js';

/*
 * Shows the full-page loading overlay (.page-loader) while a page loads.
 *
 * Sits on <html>, which Turbo never replaces, and toggles its `page-loading` class. The class is
 * rendered by the server so the overlay shows from the first paint; it is removed once the window
 * has loaded. Turbo visits and form submissions show it again after a short delay (fast pages
 * never flash it) until the next page is rendered.
 *
 * A page can keep the overlay up after load until one of its parts is ready: set the `wait-for` value
 * to an event name (e.g. "login-globe:ready"); the overlay then hides when that event reaches the
 * document, or after `timeout` ms at the latest.
 *
 * A form that skips Turbo (data-turbo="false", like the login form, so the next page gets a full
 * load and keeps its own overlay until its window has loaded) can show it with
 * data-action="page-loader#show".
 *
 * Skipped: same-page refreshes (auto-refresh ticks) and visits started inside an area that shows
 * its own indicator (the `loading` controller's `.is-loading`). If this controller never runs, a
 * CSS fail-safe hides the overlay after a few seconds.
 *
 * Right after signing in or out (see ../rocket_swipe.js), the page the user arrives on does not fade its overlay
 * out: once it is ready, the pixel rocket crosses the screen and wipes the overlay away. The arrival value says
 * which kind of page this is ("app" or "sign-in"), so a failed sign-in, back on the sign-in page, just fades.
 *
 * <html class="page-loading" data-controller="page-loader" data-page-loader-wait-for-value="login-globe:ready"
 *       data-page-loader-arrival-value="sign-in">
 */
const DELAY_MS = 200;

export default class extends Controller {
    static values = { waitFor: String, timeout: { type: Number, default: 6000 }, arrival: String };

    connect() {
        this.done = this.done.bind(this);
        this.loaded = this.loaded.bind(this);
        // Until the first page has fully shown, only load + the wait-for signal may hide the overlay.
        this.waiting = Boolean(this.waitForValue);
        this.onSignal = () => { this.signalled = true; if (document.readyState === 'complete') this.done(); };
        this.onTurboLoad = () => { if (!this.waiting) this.done(); };
        this.onPageShow = (event) => { if (event.persisted) this.done(); };
        if (this.waitForValue) document.addEventListener(this.waitForValue, this.onSignal);
        this.onVisit = this.onVisit.bind(this);
        this.onSubmitEnd = (event) => { if (!event.detail.success) this.done(); };

        document.addEventListener('turbo:visit', this.onVisit);
        document.addEventListener('turbo:submit-start', this.onVisit);
        document.addEventListener('turbo:submit-end', this.onSubmitEnd);
        document.addEventListener('turbo:load', this.onTurboLoad);
        document.addEventListener('turbo:fetch-request-error', this.done);
        // Back/forward restores from bfcache without firing load.
        window.addEventListener('pageshow', this.onPageShow);

        if (document.readyState === 'complete') {
            this.loaded();
        } else {
            window.addEventListener('load', this.loaded, { once: true });
        }
    }

    /** The window has loaded: hide the overlay, unless the page is still waiting for its signal. */
    loaded() {
        if (!this.waitForValue || this.signalled) {
            this.done();
            return;
        }
        this.fallback = setTimeout(this.done, this.timeoutValue);
    }

    disconnect() {
        document.removeEventListener('turbo:visit', this.onVisit);
        document.removeEventListener('turbo:submit-start', this.onVisit);
        document.removeEventListener('turbo:submit-end', this.onSubmitEnd);
        document.removeEventListener('turbo:load', this.onTurboLoad);
        document.removeEventListener('turbo:fetch-request-error', this.done);
        window.removeEventListener('pageshow', this.onPageShow);
        window.removeEventListener('load', this.loaded);
        if (this.waitForValue) document.removeEventListener(this.waitForValue, this.onSignal);
        this.done();
    }

    onVisit(event) {
        const url = event.detail?.url;
        if (url && url === window.location.href && event.detail.action === 'replace') return;
        if (document.querySelector('.is-loading')) return;
        this.show();
    }

    /** Shows the overlay after a short delay; also usable as an action, e.g. on a data-turbo="false" form's submit. */
    show(event) {
        if (event?.defaultPrevented) return; // e.g. a submit cancelled by field-validation
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            this.element.classList.add('page-loading');
            this.element.setAttribute('aria-busy', 'true');
        }, DELAY_MS);
    }

    done() {
        clearTimeout(this.timer);
        clearTimeout(this.fallback);
        this.waiting = false;
        if (this.swiping) return; // the swipe hides the overlay when it ends

        const overlay = this.element.querySelector('.page-loader');
        if (overlay && this.arrivalValue && this.element.classList.contains('page-loading') && takeRocketSwipe(this.arrivalValue)) {
            this.swiping = true;
            rocketSwipe(overlay).then((restore) => {
                this.swiping = false;
                this.hide();
                // Only once the overlay's own fade-out (up to .45s) is over, or it would show again meanwhile.
                setTimeout(restore, 600);
            });
            return;
        }
        this.hide();
    }

    hide() {
        // swipe-pending (base.html.twig) kept the overlay solid for the swipe; later loads get the usual tint.
        this.element.classList.remove('page-loading', 'swipe-pending');
        this.element.removeAttribute('aria-busy');
    }
}
