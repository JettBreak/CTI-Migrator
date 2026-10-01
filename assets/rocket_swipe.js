/*
 * The rocket swipe between the signed-in and signed-out halves of the app, in two parts across the page change.
 * Signing in or out (controllers/rocket_swipe_controller.js) leaves a note in this tab's session storage saying
 * where it is headed; the next page, if it is that kind of page, takes the note and, once ready, instead of
 * fading its loading overlay out has the pixel rocket cross the screen and wipe the overlay away behind its
 * glowing trail (controllers/page_loader_controller.js). The rocket, its trail and the sparks are cloned from
 * <template id="rocket-swipe"> (templates/_rocket_swipe.html.twig).
 *
 * A theme without space scenery marks the template with its own transition (App\Enum\AppTheme::transition()):
 * data-style="blocks" dissolves the overlay in square blocks in random order, like an old console changing scenes;
 * "lines" clears it line by line from the top, like a terminal.
 */
const KEY = 'rocket-swipe';
const FRESH_MS = 30000; // a note older than this is from an abandoned sign-in or sign-out
const DURATION_MS = 1500;
const EASING = 'cubic-bezier(.4,0,.6,1)'; // in at once, fast across, easing out at the far edge
const SPARKS = 26;
const DISSOLVE_MS = 900;

/**
 * Asks the next page to end its loading overlay with the swipe, if it is a $to page: "app" (signed in) or
 * "sign-in" (signed out). Not for users who prefer reduced motion.
 */
export function requestRocketSwipe(to) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ to, at: Date.now() }));
    } catch {
        // Storage blocked: the next page just fades its overlay out as usual.
    }
}

/**
 * Whether a sign-in or sign-out just now asked this page, a $here page, for the swipe. The note is used up
 * either way: after a failed sign-in, the sign-in page takes the note meant for the app and fades as usual.
 */
export function takeRocketSwipe(here) {
    try {
        const note = JSON.parse(sessionStorage.getItem(KEY) || 'null');
        sessionStorage.removeItem(KEY);

        return null !== note && note.to === here && Date.now() - note.at < FRESH_MS
            && typeof Element.prototype.animate === 'function'
            && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
        return false;
    }
}

/**
 * The rocket crosses the window left to right; $cover (the loading overlay) is cut away behind its tail.
 * Resolves when it is done, or shortly after it should have been, whatever happens, with a function that
 * restores $cover: call it once the cover is hidden (it stays cut away until then).
 */
export function rocketSwipe(cover) {
    const template = document.getElementById('rocket-swipe');
    if (!template) return Promise.resolve(() => {});

    if ('blocks' === template.dataset.style) return blockDissolve(cover, true);
    if ('lines' === template.dataset.style) return blockDissolve(cover, false);

    const scene = template.content.firstElementChild.cloneNode(true);
    document.body.appendChild(scene);
    const width = window.innerWidth;
    const from = -0.16 * width, to = 1.08 * width; // where the rocket's tail, the edge of the wipe, runs
    const timing = { duration: DURATION_MS, easing: EASING, fill: 'forwards' };

    const wipe = cover.animate([{ clipPath: `inset(0 0 0 ${from}px)` }, { clipPath: `inset(0 0 0 ${to}px)` }], timing);
    const flight = scene.querySelector('.rocket-swipe-front')
        .animate([{ transform: `translateX(${from}px)` }, { transform: `translateX(${to}px)` }], timing);
    sparks(scene.querySelector('.rocket-swipe-sparks'), from, to);

    const done = new Promise((resolve) => {
        flight.finished.then(resolve, resolve);
        setTimeout(resolve, DURATION_MS + 300);
    });

    return done.then(() => {
        scene.remove();

        return () => wipe.cancel();
    });
}

/**
 * The overlay is swapped for a grid of blocks in its colour, which vanish one by one over DISSOLVE_MS: square
 * blocks in random order ($blocks), or full-width lines of text height from the top down. Same contract as
 * rocketSwipe(): $cover stays hidden until the returned function is called.
 */
function blockDissolve(cover, blocks) {
    const size = blocks ? Math.max(48, Math.ceil(Math.max(window.innerWidth, window.innerHeight) / 20)) : 22;
    const columns = blocks ? Math.ceil(window.innerWidth / size) : 1, rows = Math.ceil(window.innerHeight / size);
    const scene = document.createElement('div');
    scene.className = 'block-dissolve';
    scene.setAttribute('aria-hidden', 'true');
    scene.style.gridTemplateColumns = blocks ? `repeat(${columns}, ${size}px)` : '100%';
    scene.style.gridAutoRows = `${size}px`;
    scene.style.setProperty('--block-color', getComputedStyle(cover).backgroundColor);
    const pieces = Array.from({ length: columns * rows }, () => scene.appendChild(document.createElement('i')));
    document.body.appendChild(scene);
    const hide = cover.animate([{ visibility: 'hidden' }, { visibility: 'hidden' }], { duration: 1, fill: 'forwards' });

    // Each piece disappears at once (no fade) at its own moment, spread over the duration: blocks in a shuffled
    // order, lines in order from the top.
    const order = pieces.map((piece, i) => [blocks ? Math.random() : i, piece]).sort((a, b) => a[0] - b[0]);
    const animations = order.map(([, piece], i) => piece.animate([{ opacity: 1 }, { opacity: 0 }],
        { duration: 1, delay: DISSOLVE_MS * i / order.length, fill: 'forwards' }));
    const done = new Promise((resolve) => {
        Promise.all(animations.map((a) => a.finished)).then(resolve, resolve);
        setTimeout(resolve, DISSOLVE_MS + 300);
    });

    return done.then(() => {
        scene.remove();

        return () => hide.cancel();
    });
}

/** Sparks shed along the rocket's path, each where the rocket is when it appears, drifting back and fading. */
function sparks(box, from, to) {
    for (let i = 1; i < SPARKS; i++) {
        const along = i / SPARKS;
        const spark = document.createElement('i');
        spark.style.left = `${from + (to - from) * along}px`;
        spark.style.top = `${46 + Math.random() * 8}%`;
        box.appendChild(spark);
        spark.animate(
            [
                { opacity: 0, transform: 'translate(0, 0)' },
                { opacity: 1, offset: 0.1 },
                { opacity: 0, transform: `translate(${-20 - Math.random() * 60}px, ${(Math.random() - 0.5) * 60}px)` },
            ],
            { duration: 900, delay: DURATION_MS * along, easing: 'ease-out', fill: 'both' },
        );
    }
}
