// ---- Instant page changes ----
// Links and forms inside the app load with fetch() and only the page body is swapped, so the
// stylesheet, scripts and fonts are never reloaded. The address bar, Back/Forward, refresh and
// bookmarks keep working. Pages are fetched as soon as the pointer rests on a link, so most
// clicks show the next page straight away.
//
// Opt out on a link, form or any parent with data-no-ajax. Anything that isn't an app page
// (a download, the sign-in screen, another site) falls back to a normal page load.

const HEADER = 'X-Zonseo-Navigate';
const PREFETCH_DELAY = 65;
const PREFETCH_TTL = 15000;
const HISTORY_LIMIT = 20;
const SKIP_PATH = /\/(logout|export|download|print)(\/|$)|\.(pdf|csv|xlsx?|zip|docx?|png|jpe?g|svg|ics|txt|json|xml)$/i;

const prefetched = new Map();
const snapshots = new Map();
let hydrate = () => {};
let current = null;
let progressTimer = null;

export function startInstantNavigation(onPageReady) {
    if (!window.fetch || !window.history.pushState || !window.DOMParser || !document.querySelector('.z-app')) return;
    hydrate = onPageReady;
    history.scrollRestoration = 'manual';
    history.replaceState({ zonseo: true, scroll: 0 }, '', location.href);

    document.addEventListener('click', onClick);
    document.addEventListener('submit', onSubmit);
    document.addEventListener('mouseover', onHover, { passive: true });
    document.addEventListener('touchstart', onHover, { passive: true });
    window.addEventListener('popstate', onPopState);
}

// ---- Which links and forms we handle ----

function sameApp(url) {
    return url.origin === location.origin && !SKIP_PATH.test(url.pathname);
}

function optedOut(el) {
    return Boolean(el.closest('[data-no-ajax]'));
}

function linkFor(event) {
    const link = event.target.closest?.('a[href]');
    if (!link || optedOut(link) || link.target && link.target !== '_self' || link.hasAttribute('download')) return null;
    if (link.getAttribute('href').startsWith('#') || link.dataset.bsToggle) return null;
    const url = new URL(link.href, location.href);
    if (!sameApp(url)) return null;
    if (url.hash && url.pathname === location.pathname && url.search === location.search) return null;
    return url;
}

function onClick(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const url = linkFor(event);
    if (!url) return;
    event.preventDefault();
    visit(url.href);
}

let hoverTimer = null;
function onHover(event) {
    const link = event.target.closest?.('a[href]');
    if (!link || link.dataset.noPrefetch !== undefined) return;
    const url = linkFor(event);
    if (!url || url.href === location.href) return;
    clearTimeout(hoverTimer);
    const go = () => prefetch(url.href);
    if (event.type === 'touchstart') {
        go();
    } else {
        hoverTimer = setTimeout(go, PREFETCH_DELAY);
        link.addEventListener('mouseleave', () => clearTimeout(hoverTimer), { once: true });
    }
}

function onSubmit(event) {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || optedOut(form)) return;
    const submitter = event.submitter;
    if (submitter && (submitter.hasAttribute('data-no-ajax') || submitter.getAttribute('formtarget'))) return;
    if (form.target && form.target !== '_self') return;

    const action = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href);
    const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'GET').toUpperCase();
    if (!sameApp(action) || action.pathname.endsWith('/switch')) return;

    event.preventDefault();
    const data = new FormData(form, submitter || undefined);
    if (method === 'GET') {
        action.search = new URLSearchParams([...data].filter(([, v]) => typeof v === 'string')).toString();
        visit(action.href);
        return;
    }
    prefetched.clear();
    snapshots.clear();
    submitter?.setAttribute('disabled', 'disabled');
    visit(action.href, { method: 'POST', body: data }).finally(() => submitter?.removeAttribute('disabled'));
}

function onPopState(event) {
    if (!event.state?.zonseo) return;
    const snapshot = snapshots.get(location.href);
    if (snapshot && Date.now() - snapshot.at < 5 * 60000) {
        render(snapshot.html, location.href, { scroll: event.state.scroll ?? 0, record: false });
        // Show the remembered page at once, then quietly bring it up to date.
        load(location.href).then((page) => {
            if (page.kind === 'page' && page.url === location.href && page.html !== snapshot.html && window.scrollY === (event.state.scroll ?? 0)) {
                render(page.html, page.url, { scroll: window.scrollY, record: false, quiet: true });
            }
        }).catch(() => {});
        return;
    }
    visit(location.href, { history: false, scroll: event.state.scroll ?? 0 });
}

// ---- Fetching ----

function prefetch(url) {
    const hit = prefetched.get(url);
    if (hit && Date.now() - hit.at < PREFETCH_TTL) return hit.promise;
    const promise = load(url, { prefetch: true });
    prefetched.set(url, { at: Date.now(), promise });
    promise.catch(() => prefetched.delete(url));
    return promise;
}

async function load(url, { method = 'GET', body = null, prefetch: isPrefetch = false, signal } = {}) {
    const headers = { [HEADER]: '1', Accept: 'text/html, application/xhtml+xml' };
    if (isPrefetch) headers.Purpose = 'prefetch';
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const response = await fetch(url, { method, body, headers, credentials: 'same-origin', redirect: 'follow', signal });
    const away = response.headers.get('X-Zonseo-Location');
    if (away) return { kind: 'away', url: away };

    const type = response.headers.get('Content-Type') || '';
    if (!type.includes('text/html')) {
        if (method === 'GET') return { kind: 'away', url: response.url };
        return { kind: 'file', blob: await response.blob(), name: fileName(response), url: response.url };
    }
    return { kind: 'page', html: await response.text(), url: response.url, status: response.status, redirected: response.redirected, method };
}

function fileName(response) {
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(response.headers.get('Content-Disposition') || '');
    return match ? decodeURIComponent(match[1]) : '';
}

async function visit(url, { method = 'GET', body = null, history: record = true, scroll = 0 } = {}) {
    current?.abort();
    const controller = new AbortController();
    current = controller;
    startProgress();

    try {
        let page;
        const hit = method === 'GET' && prefetched.get(url);
        if (hit && Date.now() - hit.at < PREFETCH_TTL) {
            page = await hit.promise;
            prefetched.delete(url);
        } else {
            page = await load(url, { method, body, signal: controller.signal });
        }
        if (controller !== current) return;

        if (page.kind === 'away') {
            location.href = page.url;
            return;
        }
        if (page.kind === 'file') {
            saveFile(page);
            return;
        }
        if (!render(page.html, page.url, { scroll, record, replace: page.url === location.href })) {
            // Not an app page (sign-in screen, error page, another layout): show it as a normal load would.
            if (method === 'GET' || page.redirected) {
                location.href = page.url;
            } else {
                document.open();
                document.write(page.html);
                document.close();
            }
        }
    } catch (error) {
        if (error.name === 'AbortError') return;
        if (method === 'GET') location.href = url;
        else window.zonseo.toast("Couldn't reach the server. Check your connection and try again.", 'danger', 6000);
    } finally {
        if (controller === current) {
            current = null;
            stopProgress();
        }
    }
}

function saveFile({ blob, name, url }) {
    const href = URL.createObjectURL(blob);
    const link = Object.assign(document.createElement('a'), { href, download: name || url.split('/').pop() || 'download' });
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(href), 10000);
}

// ---- Swapping the page ----

function headSignature(doc) {
    return [...doc.head.querySelectorAll('link[rel="stylesheet"], link[rel="modulepreload"], script[src], style')]
        .map((el) => el.outerHTML).join('');
}

function render(html, url, { scroll = 0, record = true, replace = false, quiet = false } = {}) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    if (!doc.querySelector('.z-app') || headSignature(doc) !== headSignature(document)) return false;

    // Remember where we were on the page we are leaving, for Back.
    if (!quiet) history.replaceState({ ...(history.state || {}), zonseo: true, scroll: window.scrollY }, '', location.href);

    const keep = [...document.body.querySelectorAll(':scope > .z-toasts, :scope > #z-progress')];
    const navScroll = document.querySelector('.z-nav')?.scrollTop || 0;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => window.bootstrap.Tooltip.getInstance(el)?.dispose());

    document.title = doc.title;
    const token = doc.querySelector('meta[name="csrf-token"]')?.content;
    if (token) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);

    document.body.classList.remove('z-sidebar-open', 'modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.body.replaceChildren(...[...doc.body.childNodes].map((node) => document.importNode(node, true)));
    document.body.append(...keep);
    runScripts(document.body);

    // Keep the page as the server sent it (minus one-off messages) so Back can show it instantly.
    doc.querySelectorAll('[data-flash]').forEach((el) => el.remove());
    snapshots.delete(url);
    snapshots.set(url, { html: doc.documentElement.outerHTML, at: Date.now() });
    while (snapshots.size > HISTORY_LIMIT) snapshots.delete(snapshots.keys().next().value);

    const nav = document.querySelector('.z-nav');
    if (nav) nav.scrollTop = navScroll;

    if (record && !quiet) {
        const state = { zonseo: true, scroll: 0 };
        replace ? history.replaceState(state, '', url) : history.pushState(state, '', url);
    }
    window.scrollTo(0, scroll);
    if (!quiet && !scroll) {
        const hash = new URL(url).hash;
        if (hash) document.getElementById(decodeURIComponent(hash.slice(1)))?.scrollIntoView();
    }

    hydrate(document.body);
    if (!quiet) {
        document.dispatchEvent(new CustomEvent('zonseo:navigated', { detail: { url } }));
    }
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
            if (old.src) script.async = false;
            if (old.src) loadedScripts.add(old.src);
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
        bar.style.width = '0';
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
