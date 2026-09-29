import { Controller } from '@hotwired/stimulus';

/*
 * Shows one panel at a time, chosen with its tab.
 *
 * <div data-controller="tabs">
 *     <button type="button" data-tabs-target="tab" data-action="tabs#select">Requests</button>
 *     <button type="button" data-tabs-target="tab" data-action="tabs#select">Audit</button>
 *     <div data-tabs-target="panel">…</div>
 *     <div data-tabs-target="panel">…</div>
 * </div>
 *
 * Tabs and panels pair up by position. With data-tabs-closable-value="true" nothing is open at
 * first and choosing the open tab again closes it (used for the account actions). Without
 * JavaScript every panel stays visible.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { closable: Boolean };

    connect() {
        this.show(this.closableValue ? -1 : 0);
    }

    select(event) {
        const index = this.tabTargets.indexOf(event.currentTarget);
        this.show(this.closableValue && index === this.current ? -1 : index);
        this.panelTargets[this.current]?.querySelector('select, textarea')?.focus();
    }

    show(index) {
        this.current = index;
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', String(i === index));
            tab.classList.toggle('active', i === index);
        });
        this.panelTargets.forEach((panel, i) => { panel.hidden = i !== index; });
    }
}
