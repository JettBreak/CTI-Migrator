import { Controller } from '@hotwired/stimulus';

/*
 * A "Show password" toggle beside a password field: switches the field between password and text, and says which
 * it is (aria-pressed, and the button's label for screen readers). The field is hidden again when its form is
 * submitted, so a browser never offers to remember it as plain text.
 *
 * <label data-controller="password-reveal">Password
 *     <input type="password" data-password-reveal-target="input">
 *     <button type="button" data-password-reveal-target="button" data-action="password-reveal#toggle" aria-pressed="false" aria-label="Show password">…</button>
 * </label>
 */
export default class extends Controller {
    static targets = ['input', 'button'];

    connect() {
        this.onSubmit = () => this.show(false);
        this.inputTarget.form?.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.inputTarget.form?.removeEventListener('submit', this.onSubmit);
    }

    toggle(event) {
        event.preventDefault(); // inside a label: do not also focus or submit anything
        this.show(this.inputTarget.type === 'password');
        this.inputTarget.focus();
    }

    show(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        this.buttonTarget.setAttribute('aria-pressed', String(visible));
        this.buttonTarget.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    }
}
