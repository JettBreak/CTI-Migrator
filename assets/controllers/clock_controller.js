import { Controller } from '@hotwired/stimulus';

/*
 * A live clock in the app's timezone (APP_TIMEZONE), not the browser's: the server renders the first
 * reading, then this keeps the time and date targets current once a second. It pauses while the
 * tab is hidden.
 *
 * <div data-controller="clock" data-clock-time-zone-value="Asia/Manila">
 *     <b data-clock-target="time">18:42:07</b>
 *     <span data-clock-target="date">TUE 29 SEP 2026</span>
 * </div>
 */
export default class extends Controller {
    static targets = ['time', 'date'];
    static values = { timeZone: String };

    connect() {
        const timeZone = this.timeZoneValue || undefined;
        this.time = new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
        this.date = new Intl.DateTimeFormat('en-GB', { timeZone, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.start());
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.start();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        this.stop();
    }

    start() {
        this.stop();
        this.tick();
    }

    stop() {
        clearTimeout(this.timer);
    }

    /** Updates on the second boundary, so the seconds never skip or stall. */
    tick() {
        const now = new Date();
        this.timeTarget.textContent = this.time.format(now);
        // e.g. "Tue, 29 Sept 2026" → "TUE 29 SEP 2026"
        this.dateTarget.textContent = this.date.format(now).replace(',', '').replace(/\bSept\b/, 'Sep').toUpperCase();
        this.timer = setTimeout(() => this.tick(), 1000 - now.getMilliseconds());
    }
}
