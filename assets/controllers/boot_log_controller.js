import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/*
 * The sign-in page's scene in the developer console theme (App\Enum\AppTheme, "terminal" scenery): a terminal
 * window beside the form that types a short start-up log, each line stamped with the time in the app's timezone
 * (time-zone value), and ends at a login prompt with a blinking cursor.
 *
 * The lines are scenery: they describe the console and the app's safeguards, not the live state of any server.
 * Commands are typed out a character at a time, status lines appear whole after a short pause. It plays once per
 * page; users who prefer reduced motion get the whole log at once. Dispatches "boot-log:ready" right away (there is
 * nothing to load), for the page loader (see page_loader_controller.js).
 *
 * <div data-controller="boot-log" data-boot-log-time-zone-value="Asia/Manila">
 *     <pre data-boot-log-target="output"></pre>
 * </div>
 */
const LINES = [
    ['cmd', 'core-migration --console'],
    ['plain', 'Coreware core account migration console v1.0'],
    ['ok', 'Loading configuration'],
    ['ok', 'Opening a secure session (TLS)'],
    ['ok', 'Loading the account directories'],
    ['ok', 'Enforcing dual-control approval for every change'],
    ['ok', 'Masking card numbers'],
    ['ok', 'Starting the audit trail'],
    ['info', 'Every action is recorded with your user and the time'],
    ['ok', 'Reached target: sign-in'],
    ['cmd', 'login'],
];
const TYPE_MS = 45; // per character of a command
const PAUSE_MS = [180, 420]; // before each status line, at random within

export default class extends Controller {
    static targets = ['output'];
    static values = { timeZone: String };

    connect() {
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.clock = this.formatter();
        this.dispatch('ready');
        this.play();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    formatter() {
        try {
            return new Intl.DateTimeFormat('en-GB', { timeZone: this.timeZoneValue || undefined, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
        } catch {
            return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
        }
    }

    play() {
        const output = this.outputTarget;
        output.textContent = '';
        if (this.still) {
            for (const [kind, text] of LINES) this.line(kind).append(text);
            this.prompt();

            return;
        }

        let i = 0;
        const next = () => {
            if (i >= LINES.length) { this.prompt(); return; }
            const [kind, text] = LINES[i++];
            const into = this.line(kind);
            if ('cmd' !== kind) {
                into.append(text);
                this.timer = setTimeout(next, PAUSE_MS[0] + Math.random() * (PAUSE_MS[1] - PAUSE_MS[0]));
                return;
            }
            let typed = 0;
            const type = () => {
                into.textContent = text.slice(0, ++typed);
                this.timer = setTimeout(typed < text.length ? type : next, typed < text.length ? TYPE_MS : 500);
            };
            this.timer = setTimeout(type, 300);
        };
        this.timer = setTimeout(next, 400);
    }

    /** Starts a line: the time, its tag (a "$" prompt, [  OK  ] or [ INFO ]), and returns where its text goes. */
    line(kind) {
        const row = document.createElement('div');
        row.className = `bl-line bl-${kind}`;
        const time = document.createElement('span');
        time.className = 'bl-time';
        time.textContent = this.clock.format(new Date());
        row.append(time, ' ');
        const tag = { cmd: '$', ok: '[  OK  ]', info: '[ INFO ]', plain: '' }[kind];
        if (tag) {
            const mark = document.createElement('span');
            mark.className = 'bl-tag';
            mark.textContent = tag;
            row.append(mark, ' ');
        }
        const text = document.createElement('span');
        row.append(text);
        this.outputTarget.append(row);

        return text;
    }

    /** The end of the log: a login prompt with a blinking cursor. */
    prompt() {
        const row = document.createElement('div');
        row.className = 'bl-line bl-prompt';
        row.textContent = 'login: ';
        const cursor = document.createElement('span');
        cursor.className = 'bl-cursor';
        row.append(cursor);
        this.outputTarget.append(row);
    }
}
