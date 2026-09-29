import { Controller } from '@hotwired/stimulus';

/*
 * − / + buttons for a number input whose browser arrows are hidden (styled in app.css).
 * They respect the input's min, max and step, and fire "input" and "change" like typing does.
 *
 * <div data-controller="number-stepper">
 *     <button type="button" data-action="number-stepper#down">−</button>
 *     <input type="number" min="1" max="10" data-number-stepper-target="input">
 *     <button type="button" data-action="number-stepper#up">+</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['input'];

    up() {
        this.step(1);
    }

    down() {
        this.step(-1);
    }

    step(direction) {
        const input = this.inputTarget;
        if ('' === input.value) {
            // An empty field starts from its minimum rather than from 0.
            input.value = input.min || '0';
        } else if (direction > 0) {
            input.stepUp();
        } else {
            input.stepDown();
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    }
}
