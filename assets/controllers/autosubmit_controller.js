import { Controller } from '@hotwired/stimulus';

/*
 * Submits the form as soon as a field changes, e.g. a filter dropdown.
 *
 * <form data-controller="autosubmit"><select data-action="change->autosubmit#submit">…</select></form>
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
