import { Controller } from '@hotwired/stimulus';

/*
 * Asks for confirmation in a modal dialog before a form is submitted.
 *
 * <form data-controller="confirm" data-action="submit->confirm#ask"
 *       data-confirm-title-value="Reject batch #12"
 *       data-confirm-message-value="Nothing is changed in core."
 *       data-confirm-confirm-label-value="Reject batch"
 *       data-confirm-tone-value="danger">
 *
 * Escape, the Cancel button or a click outside the dialog cancel. Focus starts on Cancel, so an
 * accidental Enter never confirms. The form is then submitted as usual (Turbo included), with the
 * button that was originally clicked.
 */
export default class extends Controller {
    static values = {
        title: { type: String, default: 'Please confirm' },
        message: String,
        confirmLabel: { type: String, default: 'Confirm' },
        tone: { type: String, default: 'primary' }, // primary | danger
    };

    disconnect() {
        this.dialog?.remove();
    }

    ask(event) {
        if (this.confirmed) {
            this.confirmed = false; // our own resubmission: let it through
            return;
        }
        event.preventDefault();
        this.submitter = event.submitter;
        this.open();
    }

    open() {
        this.dialog?.remove();
        const dialog = document.createElement('dialog');
        dialog.className = `confirm-modal ${this.toneValue}`;
        dialog.setAttribute('aria-labelledby', 'confirm-modal-title');
        dialog.innerHTML = `
            <div class="confirm-modal-body">
                <span class="confirm-modal-icon" aria-hidden="true">!</span>
                <div>
                    <h2 id="confirm-modal-title"></h2>
                    <p></p>
                </div>
            </div>
            <div class="confirm-modal-actions">
                <button type="button" class="button outline" data-role="cancel">Cancel</button>
                <button type="button" class="button" data-role="confirm"></button>
            </div>`;
        // Text only, never HTML: titles and messages can contain file names and user input.
        dialog.querySelector('h2').textContent = this.titleValue;
        dialog.querySelector('p').textContent = this.messageValue;
        dialog.querySelector('[data-role="confirm"]').textContent = this.confirmLabelValue;

        dialog.querySelector('[data-role="cancel"]').addEventListener('click', () => this.dismiss());
        dialog.querySelector('[data-role="confirm"]').addEventListener('click', () => this.confirm());
        // Escape.
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.dismiss();
        });
        // A click on the backdrop lands on the dialog element itself, outside its content box.
        dialog.addEventListener('click', (event) => {
            const box = dialog.getBoundingClientRect();
            const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
            if (event.target === dialog && outside) {
                this.dismiss();
            }
        });

        document.body.append(dialog);
        this.dialog = dialog;
        dialog.showModal();
        dialog.querySelector('[data-role="cancel"]').focus();
    }

    dismiss() {
        this.dialog?.close();
        this.dialog?.remove();
        this.dialog = null;
    }

    confirm() {
        this.dismiss();
        this.confirmed = true;
        this.element.requestSubmit(this.submitter?.form === this.element ? this.submitter : null);
    }
}
