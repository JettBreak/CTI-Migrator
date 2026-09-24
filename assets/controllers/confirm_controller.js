import { Controller } from '@hotwired/stimulus';

/*
 * Asks for confirmation before a form is submitted.
 *
 * <form data-controller="confirm" data-confirm-message-value="Apply to core?" data-action="submit->confirm#ask">
 */
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
