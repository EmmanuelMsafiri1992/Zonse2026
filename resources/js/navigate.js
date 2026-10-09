// ---- Instant page changes ----
// Links and forms inside the app load with fetch() and only the page body is swapped, so the
// stylesheet, scripts and fonts are never reloaded. The address bar keeps the address the app was
// opened on; the page on screen is tracked here instead. Back/Forward and refresh still return to
// the right page, and each request tells the server which page it came from (X-Zonseo-Page).
//
// Pages open at once because they are usually fetched before the click:
//  - after each page appears, the sidebar's pages are quietly fetched one by one;
//  - a link is fetched as soon as the pointer rests on it, touches it or presses it.
// A page already fetched is shown straight away and then refreshed in the background. A page
// that isn't ready yet shows a placeholder layout until it arrives.
//
// Opt out on a link, form or any parent with data-no-ajax; data-no-prefetch keeps a link from
// being fetched early (for links that change something when opened). Anything that isn't an app
// page (a download, the sign-in screen, another site) falls back to a normal page load.

const HEADER = 'X-Zonseo-Navigate';
const PAGE_HEADER = 'X-Zonseo-Page';
const HOVER_DELAY = 65;
const SHOW_AT_ONCE_FOR = 5 * 60000;
const REFRESH_AFTER = 10000;
const WARM_AGAIN_AFTER = 60000;
const WARM_LIMIT = 8;
const WARM_START = 1200;
const WARM_SLOW = 1500;
const PAGE_LIMIT = 30;
const SKELETON_DELAY = 60;
const SKIP_PATH = /\/(logout|export|download|print)(\/|$)|\.(pdf|csv|xlsx?|zip|docx?|png|jpe?g|svg|ics|txt|json|xml)$/i;

/** Pages as the server sent them, by URL: { html, at } */
const pages = new Map();
/** Requests on their way, by URL, so a page is never fetched twice at once. */
const inflight = new Map();
/** Background requests that can be dropped to make way for a click: url => AbortController */
const background = new Map();

let hydrate = () => {};
/** The page on screen, which the address bar no longer shows. */
let current = location.href;
/** Set when a page arrives built with newer stylesheets/scripts than the ones loaded. */
let staleAssets = false;
/** True while reopening the page that was on screen before a full reload. */
let restoring = false;
let navigation = 0;
let interactions = 0;
let progressTimer = null;

export function startInstantNavigation(onPageReady) {
    if (!window.fetch || !window.history.pushState || !window.DOMParser || !document.querySelector('.z-app')) return;
    hydrate = onPageReady;
    history.scrollRestoration = 'manual';
    // After a refresh (or coming Back from another site) the address bar still holds the first page,
    // but the history entry remembers the one that was on screen.
    const restore = history.state?.zonseo && history.state.page && history.state.page !== location.href ? history.state : null;
    history.replaceState({ zonseo: true, page: restore?.page || location.href, scroll: restore?.scroll || 0 }, '');
    setBase(current);
    window.zonseo.reload = reload;

    document.addEventListener('click', onClick);
    document.addEventListener('submit', onSubmit);
    document.addEventListener('mouseover', onPointer, { passive: true });
    document.addEventListener('touchstart', onPointer, { passive: true });
    document.addEventListener('mousedown', onPointer, { passive: true });
    window.addEventListener('popstate', onPopState);
    ['keydown', 'input', 'pointerdown', 'focusin'].forEach((type) => document.addEventListener(type, () => { interactions++; }, { passive: true, capture: true }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) warmUp(); });

    if (restore) {
        restoring = true;
        showSkeleton(null);
        visit(restore.page, { record: false, scroll: restore.scroll || 0 });
    } else {
        warmUp();
    }
}

/**
 * Show the page on screen again with fresh data, without the placeholder. Pages that refresh
 * themselves pass their own address so a timer left running never reloads a page opened since.
 */
function reload(onlyOn = null) {
    if (onlyOn && new URL(onlyOn, current).pathname !== new URL(current).pathname) return;
    const url = current;
    pages.delete(url);
    fetchPage(url).then((page) => {
        if (url !== current) return;
        if (page.kind !== 'page' || !render(page.html, url, { record: false, scroll: window.scrollY, quiet: true })) open(page, url, { record: false });
    }).catch(() => { location.href = url; });
}

/** Relative links and fetches in page scripts resolve against the page on screen, not the address bar. */
function setBase(url) {
    let base = document.head.querySelector('base[data-zonseo]');
    if (!base) {
        base = Object.assign(document.createElement('base'), { href: url });
        base.dataset.zonseo = '';
        document.head.prepend(base);
    }
    base.href = url;
}

// ---- Which links and forms we handle ----

function sameApp(url) {
    return url.origin === location.origin && !SKIP_PATH.test(url.pathname);
}

function optedOut(el) {
    return Boolean(el.closest('[data-no-ajax]'));
}

function urlOf(link) {
    if (!link || optedOut(link) || link.target && link.target !== '_self' || link.hasAttribute('download')) return null;
    const href = link.getAttribute('href') || '';
    if (href === '' || href.startsWith('#') || href.startsWith('javascript:') || link.dataset.bsToggle) return null;
    const url = new URL(link.href, current);
    const here = new URL(current);
    if (!sameApp(url)) return null;
    if (url.hash && url.pathname === here.pathname && url.search === here.search) return null;
    return url;
}

function onClick(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest?.('a[href]');
    if (link && link.getAttribute('href').startsWith('#') && !optedOut(link)) {
        // With the address bar on another page, the browser would treat "#..." as a link to that page.
        event.preventDefault();
        const id = decodeURIComponent(link.getAttribute('href').slice(1));
        if (id && !link.dataset.bsToggle) document.getElementById(id)?.scrollIntoView();
        return;
    }
    const url = urlOf(link);
    if (!url) return;
    event.preventDefault();
    visit(url.href, { link });
}

let hoverTimer = null;
function onPointer(event) {
    const link = event.target.closest?.('a[href]');
    if (!link || link.dataset.noPrefetch !== undefined) return;
    const url = urlOf(link);
    if (!url || url.href === current || ready(url.href, REFRESH_AFTER)) return;
    clearTimeout(hoverTimer);
    if (event.type === 'mouseover') {
        hoverTimer = setTimeout(() => fetchPage(url.href, { early: true }), HOVER_DELAY);
        link.addEventListener('mouseleave', () => clearTimeout(hoverTimer), { once: true });
    } else {
        fetchPage(url.href, { early: true });
    }
}

function onSubmit(event) {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || optedOut(form)) return;
    const submitter = event.submitter;
    if (submitter && (submitter.hasAttribute('data-no-ajax') || submitter.getAttribute('formtarget'))) return;
    if (form.target && form.target !== '_self') return;

    const action = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || current, current);
    const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'GET').toUpperCase();
    if (!sameApp(action) || action.pathname.endsWith('/switch')) return;

    event.preventDefault();
    const data = new FormData(form, submitter || undefined);
    if (method === 'GET') {
        action.search = new URLSearchParams([...data].filter(([, v]) => typeof v === 'string')).toString();
        visit(action.href);
        return;
    }
    // Something changed on the server, so every page we kept may be out of date.
    pages.clear();
    submitter?.setAttribute('disabled', 'disabled');
    send(action.href, data).finally(() => submitter?.removeAttribute('disabled'));
}

function onPopState(event) {
    if (!event.state?.zonseo) return;
    visit(event.state.page || location.href, { record: false, scroll: event.state.scroll ?? 0 });
}

// ---- Fetching ----

function ready(url, maxAge = SHOW_AT_ONCE_FOR) {
    const page = pages.get(url);
    return page && Date.now() - page.at < maxAge ? page : null;
}

function keep(url, html) {
    pages.delete(url);
    pages.set(url, { html, at: Date.now() });
    while (pages.size > PAGE_LIMIT) pages.delete(pages.keys().next().value);
}

/** GET a page once, however many callers ask for it at the same time. */
function fetchPage(url, { early = false } = {}) {
    if (inflight.has(url)) return inflight.get(url);
    const controller = new AbortController();
    if (early) background.set(url, controller);
    const promise = load(url, { early, signal: controller.signal })
        .then((result) => {
            if (result.kind === 'page' && result.url === url && result.status === 200 && isAppPage(result.html)) {
                keep(url, withoutFlash(result.html));
            }
            return result;
        })
        .finally(() => {
            inflight.delete(url);
            background.delete(url);
        });
    inflight.set(url, promise);
    promise.catch(() => {});
    return promise;
}

async function load(url, { method = 'GET', body = null, early = false, signal = null } = {}) {
    const headers = { [HEADER]: '1', [PAGE_HEADER]: current, Accept: 'text/html, application/xhtml+xml' };
    // Laravel leaves "previous URL" alone for prefetches, so fetching early never changes where "back" goes.
    if (early) headers.Purpose = 'prefetch';
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const response = await fetch(url, { method, body, headers, credentials: 'same-origin', redirect: 'follow', signal });
    const away = response.headers.get('X-Zonseo-Location');
    if (away) return { kind: 'away', url: away };

    const type = response.headers.get('Content-Type') || '';
    if (!type.includes('text/html')) {
        if (method === 'GET') return { kind: 'away', url: response.url };
        return { kind: 'file', blob: await response.blob(), name: fileName(response), url: response.url };
    }
    return { kind: 'page', html: await response.text(), url: response.url, status: response.status, redirected: response.redirected };
}

function fileName(response) {
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(response.headers.get('Content-Disposition') || '');
    return match ? decodeURIComponent(match[1]) : '';
}

function isAppPage(html) {
    return html.includes('class="z-app"');
}

function withoutFlash(html) {
    return html.replace(/<div data-flash="[^"]*" data-flash-type="[^"]*"><\/div>/g, '');
}

// ---- Opening a page ----

async function visit(url, { link = null, record = true, scroll = 0 } = {}) {
    const id = ++navigation;
    const cached = ready(url);

    // Already here: show it at once, then bring it up to date quietly.
    if (cached) {
        if (!render(cached.html, url, { record, scroll, replace: url === current })) {
            loadFully(url);
            return;
        }
        if (Date.now() - cached.at > REFRESH_AFTER) refresh(url, id);
        warmUp();
        return;
    }

    makeWayFor(url);
    const skeletonTimer = setTimeout(() => { if (id === navigation) showSkeleton(link); }, SKELETON_DELAY);
    startProgress();
    try {
        const page = await fetchPage(url);
        if (id !== navigation) return;
        open(page, url, { record, scroll });
    } catch (error) {
        console.warn('[zonseo] Loading the whole page instead:', error);
        if (id === navigation) location.href = url;
    } finally {
        clearTimeout(skeletonTimer);
        if (id === navigation) stopProgress();
    }
    warmUp();
}

/** Fetch a page again behind the one on screen and swap it in, unless the person has started using it. */
function refresh(url, id) {
    const before = interactions;
    const shown = pages.get(url)?.html;
    // Early, so a click elsewhere cancels it instead of waiting behind it.
    fetchPage(url, { early: true }).then((page) => {
        if (id !== navigation || interactions !== before || current !== url) return;
        if (page.kind !== 'page' || page.url !== url || withoutFlash(page.html) === shown) return;
        render(page.html, url, { record: false, scroll: window.scrollY, quiet: true });
    }).catch(() => {});
}

/** Send a form, then show wherever the server sends us. */
async function send(url, body) {
    const id = ++navigation;
    makeWayFor(null);
    startProgress();
    try {
        const page = await load(url, { method: 'POST', body });
        if (id !== navigation) return;
        if (page.kind === 'file') {
            saveFile(page);
            return;
        }
        if (page.kind === 'page' && !isAppPage(page.html) && !page.redirected) {
            // A page from another layout answered the form directly (an error page, say): show it as is.
            document.open();
            document.write(page.html);
            document.close();
            return;
        }
        open(page, url, { record: true });
    } catch (error) {
        window.zonseo.toast("Couldn't reach the server. Check your connection and try again.", 'danger', 6000);
    } finally {
        if (id === navigation) stopProgress();
    }
    warmUp();
}

function open(page, requested, { record = true, scroll = 0 } = {}) {
    if (page.kind === 'away') {
        location.href = page.url;
        return;
    }
    if (page.kind === 'file') {
        saveFile(page);
        return;
    }
    if (!render(page.html, page.url, { record, scroll, replace: page.url === current })) {
        // Not an app page (sign-in screen, another layout) or the app was updated: load it the normal way.
        loadFully(page.url || requested);
    }
}

/**
 * Load a page the normal way. When only the app's files changed (a new release), reload the address
 * already in the address bar and reopen the page from there, so the address still doesn't change.
 */
function loadFully(url) {
    if (staleAssets && !restoring && sameApp(new URL(url))) {
        history.replaceState({ zonseo: true, page: url, scroll: 0 }, '');
        location.reload();
        return;
    }
    location.href = url;
}

function saveFile({ blob, name, url }) {
    const href = URL.createObjectURL(blob);
    const link = Object.assign(document.createElement('a'), { href, download: name || url.split('/').pop() || 'download' });
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(href), 10000);
}

/** Drop background fetches so the page the person asked for is answered first. */
function makeWayFor(url) {
    background.forEach((controller, pending) => {
        if (pending !== url) controller.abort();
    });
}

// ---- Fetching the sidebar's pages before they are needed ----

let warmTimer = null;
function warmUp() {
    clearTimeout(warmTimer);
    if (document.hidden || navigator.connection?.saveData) return;
    const id = navigation;
    warmTimer = setTimeout(async () => {
        const urls = [...new Set([...document.querySelectorAll('.z-nav a[href]')]
            .filter((link) => link.dataset.noPrefetch === undefined)
            .map((link) => urlOf(link)?.href)
            .filter((url) => url && url !== current))]
            .slice(0, WARM_LIMIT)
            .filter((url) => !ready(url, WARM_AGAIN_AFTER));
        for (const url of urls) {
            // Stop as soon as the person moves on; the next page starts its own round.
            if (id !== navigation || document.hidden) return;
            const started = Date.now();
            await fetchPage(url, { early: true }).catch(() => {});
            // A struggling server would only make the next click wait behind these; try again later.
            if (Date.now() - started > WARM_SLOW) return;
            await idle();
        }
    }, WARM_START);
}

function idle() {
    return new Promise((resolve) => (window.requestIdleCallback ? requestIdleCallback(() => resolve(), { timeout: 500 }) : setTimeout(resolve, 50)));
}

// ---- Placeholder while a page that wasn't ready loads ----

function showSkeleton(link) {
    const main = document.querySelector('main.z-content');
    if (!main) return;
    if (link?.closest('.z-nav')) {
        document.querySelectorAll('.z-nav .z-nav-link.active').forEach((el) => el.classList.remove('active'));
        link.classList.add('active');
    }
    document.body.classList.remove('z-sidebar-open');
    const title = link?.textContent.trim() || '';
    main.innerHTML = `<div class="z-skeleton" aria-busy="true" aria-label="Loading">
        <div class="z-skel-crumb"></div>
        <h1 class="z-skel-heading">${title ? escapeHtml(title) : '<span class="z-skel z-skel-title"></span>'}</h1>
        <div class="z-skel z-skel-sub"></div>
        <div class="z-skel-cards">${'<div class="z-skel z-skel-card"></div>'.repeat(4)}</div>
        <div class="z-skel-table">${'<div class="z-skel z-skel-row"></div>'.repeat(6)}</div>
    </div>`;
    window.scrollTo(0, 0);
}

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ---- Swapping the page ----

function headSignature(doc) {
    return [...doc.head.querySelectorAll('link[rel="stylesheet"], link[rel="modulepreload"], script[src], style')]
        .map((el) => el.outerHTML).join('');
}

function render(html, url, { scroll = 0, record = true, replace = false, quiet = false } = {}) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    if (!doc.querySelector('.z-app')) return false;
    if (headSignature(doc) !== headSignature(document)) {
        staleAssets = true;
        return false;
    }
    restoring = false;

    // Remember where we were on the page we are leaving, for Back.
    // (On Back/Forward the browser has already moved to the entry being opened, so there is nothing to save.)
    if (!quiet && record) history.replaceState({ ...(history.state || {}), zonseo: true, page: current, scroll: window.scrollY }, '');

    const kept = [...document.body.querySelectorAll(':scope > .z-toasts, :scope > #z-progress')];
    const navScroll = document.querySelector('.z-nav')?.scrollTop || 0;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => window.bootstrap.Tooltip.getInstance(el)?.dispose());

    document.title = doc.title;
    const token = doc.querySelector('meta[name="csrf-token"]')?.content;
    if (token) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);

    document.body.classList.remove('z-sidebar-open', 'modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.body.replaceChildren(...[...doc.body.childNodes].map((node) => document.importNode(node, true)));
    document.body.append(...kept);
    runScripts(document.body);

    if (isAppPage(html)) keep(url, withoutFlash(html));
    current = url;
    setBase(url);

    const nav = document.querySelector('.z-nav');
    if (nav) nav.scrollTop = navScroll;

    if (record && !quiet) {
        // A new history entry for Back, with the address bar left as it is.
        const state = { zonseo: true, page: url, scroll: 0 };
        replace ? history.replaceState(state, '') : history.pushState(state, '');
    } else if (!quiet) {
        // Back/Forward or a refresh: the entry may have been sent on elsewhere (a redirect).
        history.replaceState({ ...(history.state || {}), zonseo: true, page: url }, '');
    }
    window.scrollTo(0, scroll);
    if (!quiet && !scroll) {
        const hash = new URL(url).hash;
        if (hash) document.getElementById(decodeURIComponent(hash.slice(1)))?.scrollIntoView();
    }

    hydrate(document.body);
    if (!quiet) document.dispatchEvent(new CustomEvent('zonseo:navigated', { detail: { url } }));
    return true;
}

// Scripts parsed from fetched HTML never run by themselves, so each one is recreated. Code waiting
// for DOMContentLoaded, load or alpine:init runs straight away, as those events have already passed.
const loadedScripts = new Set([...document.scripts].map((s) => s.src).filter(Boolean));

function runScripts(root) {
    const deferred = ['DOMContentLoaded', 'load', 'alpine:init'];
    const original = { doc: document.addEventListener, win: window.addEventListener };
    const patch = (target, add) => function (type, listener, options) {
        if (deferred.includes(type)) {
            try { typeof listener === 'function' ? listener.call(target, new Event(type)) : listener.handleEvent(new Event(type)); } catch (e) { console.error(e); }
            return;
        }
        return add.call(this, type, listener, options);
    };
    document.addEventListener = patch(document, original.doc);
    window.addEventListener = patch(window, original.win);
    try {
        root.querySelectorAll('script').forEach((old) => {
            if (old.type && !['text/javascript', 'module', 'application/javascript'].includes(old.type)) return;
            if (old.src && loadedScripts.has(old.src)) return;
            const script = document.createElement('script');
            [...old.attributes].forEach((attr) => script.setAttribute(attr.name, attr.value));
            script.textContent = old.textContent;
            if (old.src) {
                script.async = false;
                loadedScripts.add(old.src);
            }
            old.replaceWith(script);
        });
    } finally {
        document.addEventListener = original.doc;
        window.addEventListener = original.win;
    }
}

// ---- Progress bar ----

function progressBar() {
    let bar = document.getElementById('z-progress');
    if (!bar) {
        bar = Object.assign(document.createElement('div'), { id: 'z-progress' });
        document.body.appendChild(bar);
    }
    return bar;
}

function startProgress() {
    clearTimeout(progressTimer);
    // Pages that arrive quickly never show the bar at all.
    progressTimer = setTimeout(() => {
        const bar = progressBar();
        bar.className = '';
        void bar.offsetWidth;
        bar.className = 'is-loading';
    }, 120);
}

function stopProgress() {
    clearTimeout(progressTimer);
    const bar = document.getElementById('z-progress');
    if (!bar || !bar.classList.contains('is-loading')) return;
    bar.className = 'is-done';
    setTimeout(() => { bar.className = ''; }, 350);
}
