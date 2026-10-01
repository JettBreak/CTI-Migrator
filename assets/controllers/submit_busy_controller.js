import { Controller } from '@hotwired/stimulus';

/*
 * While a form that leaves the page (data-turbo="false") waits for the server, its submit button says so: a spinner
 * and the busy label (e.g. "Signing in…"), aria-busy, and further clicks ignored, so it is not sent twice. Nothing
 * else on the page changes. Left alone: a submit another action cancelled (e.g. field-validation finding an empty
 * field). Coming back to the page from the browser's history resets the button.
 *
 * <form data-turbo="false" data-controller="submit-busy" data-action="submit->submit-busy#start" data-submit-busy-label-value="Signing in…">
 *     <button type="submit" data-submit-busy-target="button">Sign in</button>
 * </form>
 */
export default class extends Controller {
    static targets = ['button'];
    static values = { label: { type: String, default: 'Working…' } };

    connect() {
        this.idle = this.buttonTarget.textContent;
        this.onPageShow = (event) => { if (event.persisted) this.reset(); };
        window.addEventListener('pageshow', this.onPageShow);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.onPageShow);
    }

    start(event) {
        if (event.defaultPrevented) return;
        if (this.busy) {
            event.preventDefault(); // already on its way
            return;
        }
        this.busy = true;
        const button = this.buttonTarget;
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
        button.textContent = this.labelValue;
    }

    reset() {
        this.busy = false;
        const button = this.buttonTarget;
        button.classList.remove('is-busy');
        button.removeAttribute('aria-busy');
        button.textContent = this.idle;
    }
}
