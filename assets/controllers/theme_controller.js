import { Controller } from '@hotwired/stimulus';

/*
 * The theme switch in the header: System (follow the browser or OS setting), Light or Dark. The choice is
 * kept in this browser (localStorage "theme"; none means System), not on the account.
 *
 * It sets <html data-theme="light|dark">, which app.css keys the dark theme on. The inline script in
 * base.html.twig does the same before the first paint, so a page never flashes the other theme; this
 * keeps it current when an option is clicked, and when the system setting changes while System is chosen.
 *
 * The change is animated (see "Theme change" in app.css): a click reveals the new theme in a circle
 * spreading from the button, a system change crossfades, both with the View Transitions API, which
 * snapshots the whole page (gradients and star canvases included). Browsers without it fade the colours
 * instead. Instant for users who prefer reduced motion, and when the theme does not actually change.
 *
 * <div data-controller="theme" role="group" aria-label="Theme">
 *     <button type="button" data-theme-target="option" data-action="theme#choose" data-theme-choice-param="system">…</button>
 * </div>
 */
const KEY = 'theme';
const FADE_MS = 1050; // the fallback fade in app.css (html.theme-fading) lasts 1s

export default class extends Controller {
    static targets = ['option'];

    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.onSystemChange = () => this.transition(this.stored());
        this.media.addEventListener('change', this.onSystemChange);
        this.apply(this.stored());
    }

    disconnect() {
        this.media.removeEventListener('change', this.onSystemChange);
        clearTimeout(this.fadeTimer);
    }

    choose({ params: { choice }, currentTarget }) {
        try {
            if (choice === 'system') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, choice);
        } catch {
            // Storage blocked (e.g. a private window): the choice holds for this page only.
        }
        this.transition(choice, currentTarget);
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

    /** @returns {'light'|'dark'} */
    resolve(choice) {
        return choice === 'dark' || (choice === 'system' && this.media.matches) ? 'dark' : 'light';
    }

    apply(choice) {
        document.documentElement.dataset.theme = this.resolve(choice);
        this.optionTargets.forEach((option) => {
            option.setAttribute('aria-pressed', String(option.dataset.themeChoiceParam === choice));
        });
    }

    /** Applies $choice, animating the change of theme; $from (the clicked button) is where the reveal starts. */
    transition(choice, from = null) {
        const root = document.documentElement;
        if (this.resolve(choice) === root.dataset.theme || this.reducedMotion.matches) {
            this.apply(choice);
            return;
        }

        if (!document.startViewTransition) {
            root.classList.add('theme-fading');
            this.apply(choice);
            clearTimeout(this.fadeTimer);
            this.fadeTimer = setTimeout(() => root.classList.remove('theme-fading'), FADE_MS);
            return;
        }

        if (from) {
            // The circle grows from the button's centre until it covers the farthest corner of the window.
            const { left, top, width, height } = from.getBoundingClientRect();
            const x = left + width / 2, y = top + height / 2;
            root.style.setProperty('--theme-x', `${x}px`);
            root.style.setProperty('--theme-y', `${y}px`);
            root.style.setProperty('--theme-r', `${Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y))}px`);
            root.classList.add('theme-reveal');
        }
        document.startViewTransition(() => this.apply(choice)).finished.finally(() => root.classList.remove('theme-reveal'));
    }
}
