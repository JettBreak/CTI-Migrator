import { Controller } from '@hotwired/stimulus';

/*
 * The theme switch in the header: System (follow the browser or OS setting), Light or Dark. The choice is
 * kept in this browser (localStorage "theme"; none means System), not on the account.
 *
 * It sets <html data-theme="light|dark">, which app.css keys the dark theme on. The inline script in
 * base.html.twig does the same before the first paint, so a page never flashes the other theme; this
 * keeps it current when an option is clicked, and when the system setting changes while System is chosen.
 *
 * <div data-controller="theme" role="group" aria-label="Theme">
 *     <button type="button" data-theme-target="option" data-action="theme#choose" data-theme-choice-param="system">…</button>
 * </div>
 */
const KEY = 'theme';

export default class extends Controller {
    static targets = ['option'];

    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.onSystemChange = () => this.apply(this.stored());
        this.media.addEventListener('change', this.onSystemChange);
        this.apply(this.stored());
    }

    disconnect() {
        this.media.removeEventListener('change', this.onSystemChange);
    }

    choose({ params: { choice } }) {
        try {
            if (choice === 'system') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, choice);
        } catch {
            // Storage blocked (e.g. a private window): the choice holds for this page only.
        }
        this.apply(choice);
    }

    /** @returns {'system'|'light'|'dark'} */
    stored() {
        try {
            const choice = localStorage.getItem(KEY);

            return choice === 'light' || choice === 'dark' ? choice : 'system';
        } catch {
            return 'system';
        }
    }

    apply(choice) {
        const dark = choice === 'dark' || (choice === 'system' && this.media.matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        this.optionTargets.forEach((option) => {
            option.setAttribute('aria-pressed', String(option.dataset.themeChoiceParam === choice));
        });
    }
}
