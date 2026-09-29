import { Controller } from '@hotwired/stimulus';
import { requestRocketSwipe } from '../rocket_swipe.js';

/*
 * Signing in or out: asks the next page to end its loading overlay with the rocket swipe (see ../rocket_swipe.js),
 * and, with the loader value, shows the loading overlay at once, solid (swipe-pending, as the next page shows it
 * too: app.css), so the backdrop does not change from this page to the next. The link or form then goes ahead as usual;
 * nothing waits for an animation. Left alone: modified clicks (new tab or window), and submits another
 * action cancelled (e.g. field-validation finding an empty field).
 *
 * <a href="/logout" data-controller="rocket-swipe" data-action="rocket-swipe#depart"
 *    data-rocket-swipe-to-param="sign-in" data-rocket-swipe-loader-param="true">Sign out</a>
 * <form data-controller="rocket-swipe" data-action="submit->rocket-swipe#depart" data-rocket-swipe-to-param="app">
 */
export default class extends Controller {
    depart(event) {
        if (event.defaultPrevented) return;
        if ('click' === event.type && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) return;

        requestRocketSwipe(event.params.to);
        if (event.params.loader) {
            document.documentElement.classList.add('page-loading', 'swipe-pending');
            document.documentElement.setAttribute('aria-busy', 'true');
        }
    }
}
