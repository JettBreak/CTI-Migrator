import { Controller } from '@hotwired/stimulus';

/*
 * Marks an area as loading while the page Turbo loads next is on its way, e.g. a directory table
 * after its status filter changes or a page number is clicked.
 *
 * <section data-controller="loading" data-action="submit->loading#start click->loading#follow">
 *     … <form>…</form> … <div class="pagination"><a href="?page=2">2</a></div>
 * </section>
 *
 * The element gets the `is-loading` class and aria-busy="true"; the next page replaces it. The
 * state is cleared if the request fails, and before Turbo caches the page (so Back never shows it
 * loading). `follow` only reacts to plain clicks on links inside `.pagination`.
 */
export default class extends Controller {
    connect() {
        this.clear = this.clear.bind(this);
        document.addEventListener('turbo:before-cache', this.clear);
        document.addEventListener('turbo:fetch-request-error', this.clear);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.clear);
        document.removeEventListener('turbo:fetch-request-error', this.clear);
        this.clear();
    }

    start() {
        this.element.classList.add('is-loading');
        this.element.setAttribute('aria-busy', 'true');
    }

    follow(event) {
        const link = event.target.closest('.pagination a[href]');
        // Ctrl/Cmd/Shift/middle clicks open a new tab or window: this page is not reloading.
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        this.start();
    }

    clear() {
        this.element.classList.remove('is-loading');
        this.element.removeAttribute('aria-busy');
    }
}
