import { Controller } from '@hotwired/stimulus';

/*
 * Replaces the browser's validation bubbles with inline messages under each field.
 *
 * Uses the fields' own constraints (required, type, pattern, …). On submit, every invalid field is
 * marked and gets a message below it, the first one is focused and the submit is cancelled; typing
 * a valid value clears the field's message. The message is the field's data-validation-message, or
 * "<label> is required." for an empty required field, or else the browser's own text.
 *
 * A file field with data-max-bytes is also invalid when a chosen file is larger, with its
 * data-size-message as the message.
 *
 * Put this action before any other submit action (e.g. page-loader#show), which can check
 * event.defaultPrevented.
 *
 * <form data-controller="field-validation" data-action="field-validation#validate page-loader#show">
 */
const ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 7v6"/><path d="M12 17h.01"/></svg>';

export default class extends Controller {
    connect() {
        this.element.noValidate = true;
        this.onInput = (event) => {
            this.checkSize(event.target);
            if (event.target.validity?.valid) this.clear(event.target);
        };
        this.element.addEventListener('input', this.onInput);
    }

    disconnect() {
        this.element.removeEventListener('input', this.onInput);
    }

    validate(event) {
        const fields = [...this.element.elements].filter((field) => field.willValidate);
        fields.forEach((field) => { this.clear(field); this.checkSize(field); });
        const invalid = fields.filter((field) => !field.checkValidity());
        if (!invalid.length) return;

        event.preventDefault();
        invalid.forEach((field) => this.show(field));
        invalid[0].focus();
    }

    checkSize(field) {
        if (field.type !== 'file' || !field.dataset.maxBytes) return;
        const tooLarge = [...field.files].some((file) => file.size > Number(field.dataset.maxBytes));
        field.setCustomValidity(tooLarge ? field.dataset.sizeMessage || 'The file is too large.' : '');
    }

    show(field) {
        const message = document.createElement('span');
        message.id = `${field.name || field.id}-error`;
        message.className = 'field-error';
        message.setAttribute('role', 'alert');
        message.innerHTML = ICON;
        message.append(this.messageFor(field));
        field.after(message);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', message.id);
    }

    clear(field) {
        if (field.getAttribute('aria-invalid') !== 'true') return;
        document.getElementById(field.getAttribute('aria-describedby'))?.remove();
        field.removeAttribute('aria-invalid');
        field.removeAttribute('aria-describedby');
    }

    messageFor(field) {
        if (field.validity.customError) return field.validationMessage;
        if (field.dataset.validationMessage) return field.dataset.validationMessage;
        if (field.validity.valueMissing) {
            const label = field.labels?.[0]?.firstChild?.textContent.trim();
            return `${label || 'This field'} is required.`;
        }

        return field.validationMessage;
    }
}
