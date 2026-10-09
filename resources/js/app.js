import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';
import { passkeyLogin, passkeyRegister } from './passkeys';
import { startInstantNavigation } from './navigate';

window.bootstrap = bootstrap;
window.Alpine = Alpine;

// ---- Sidebar state (collapsed on desktop / open on mobile) ----
// The body element stays the same when instant navigation swaps the page, so these classes persist.
const body = document.body;
const SIDEBAR_KEY = 'zonseo.sidebar.collapsed';
try { if (localStorage.getItem(SIDEBAR_KEY) === '1') body.classList.add('z-sidebar-collapsed'); } catch (e) {}

window.zonseo = {
    toggleSidebar() {
        if (window.innerWidth < 992) {
            body.classList.toggle('z-sidebar-open');
        } else {
            body.classList.toggle('z-sidebar-collapsed');
            try { localStorage.setItem(SIDEBAR_KEY, body.classList.contains('z-sidebar-collapsed') ? '1' : '0'); } catch (e) {}
        }
    },
    closeSidebar() { body.classList.remove('z-sidebar-open'); },
    /** Show the current page again with fresh data; instant navigation does this without a full reload. */
    reload() { window.location.reload(); },
    toast(message, type = 'success', timeout = 3500) {
        let wrap = document.querySelector('.z-toasts');
        if (!wrap) { wrap = document.createElement('div'); wrap.className = 'z-toasts'; document.body.appendChild(wrap); }
        const el = document.createElement('div');
        el.className = `z-toast ${type}`;
        el.innerHTML = `<span>${message}</span>`;
        wrap.appendChild(el);
        setTimeout(() => el.remove(), timeout);
    },
    confirm(form, message) {
        // requestSubmit, unlike submit, lets instant navigation send the form in-page.
        if (window.confirm(message || 'Are you sure?')) form.requestSubmit ? form.requestSubmit() : form.submit();
    },
};

// ---- Alpine stores & helpers ----
Alpine.store('ui', {
    get sidebarCollapsed() { return body.classList.contains('z-sidebar-collapsed'); },
});

Alpine.data('selectable', (initial = []) => ({
    selected: new Set(initial),
    toggle(key) { this.selected.has(key) ? this.selected.delete(key) : this.selected.add(key); },
    has(key) { return this.selected.has(key); },
    get list() { return Array.from(this.selected); },
}));

Alpine.data('passkeyLogin', passkeyLogin);
Alpine.data('passkeyRegister', passkeyRegister);

// ---- Guided tours: point at one element at a time, skipping any that are not on screen ----
Alpine.data('zTour', (allSteps = [], doneUrl = '', autoStart = false) => ({
    allSteps, steps: [], index: 0, open: false, ring: null, card: '',
    get step() { return this.steps[this.index] || { title: '', text: '' }; },
    init() { if (autoStart) setTimeout(() => this.start(), 600); },
    visible(selector) {
        if (!selector) return true;
        const el = document.querySelector(selector);
        if (!el) return false;
        const box = el.getBoundingClientRect();
        return box.width > 0 && box.height > 0 && getComputedStyle(el).visibility !== 'hidden';
    },
    start() {
        this.steps = this.allSteps.filter((step) => this.visible(step.target));
        if (!this.steps.length) return;
        this.open = true;
        this.go(0);
    },
    go(index) {
        this.index = Math.max(0, Math.min(index, this.steps.length - 1));
        const el = this.step.target ? document.querySelector(this.step.target) : null;
        if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        this.$nextTick(() => this.place());
    },
    place() {
        if (!this.open) return;
        const el = this.step.target ? document.querySelector(this.step.target) : null;
        const cardWidth = Math.min(320, window.innerWidth - 32);
        if (!el) {
            this.ring = null;
            this.card = `width:${cardWidth}px;left:50%;top:50%;transform:translate(-50%,-50%)`;
            return;
        }
        const box = el.getBoundingClientRect();
        const pad = 6;
        this.ring = `top:${box.top - pad}px;left:${box.left - pad}px;width:${box.width + pad * 2}px;height:${box.height + pad * 2}px`;
        const cardHeight = this.$refs.card ? this.$refs.card.offsetHeight : 160;
        let top = box.bottom + 14;
        if (top + cardHeight > window.innerHeight - 16) top = Math.max(16, box.top - cardHeight - 14);
        let left = Math.min(Math.max(16, box.left), window.innerWidth - cardWidth - 16);
        if (box.left > window.innerWidth / 2) left = Math.max(16, Math.min(box.right - cardWidth, window.innerWidth - cardWidth - 16));
        this.card = `width:${cardWidth}px;top:${top}px;left:${left}px`;
    },
    key(event) {
        if (!this.open) return;
        if (event.key === 'Escape') this.close('dismissed');
        if (event.key === 'ArrowRight') this.index < this.steps.length - 1 ? this.go(this.index + 1) : this.close('completed');
        if (event.key === 'ArrowLeft' && this.index > 0) this.go(this.index - 1);
    },
    close(outcome) {
        this.open = false;
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(doneUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ outcome }),
        }).catch(() => {});
    },
}));

Alpine.start();

// ---- Run on first load and after every instant page change ----
function pageReady(root) {
    // Bootstrap tooltips
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => bootstrap.Tooltip.getOrCreateInstance(el));

    // Flash toasts rendered by the server
    root.querySelectorAll('[data-flash]').forEach((el) => {
        window.zonseo.toast(el.dataset.flash, el.dataset.flashType || 'success');
        el.remove();
    });
}

pageReady(document);
startInstantNavigation(pageReady);

// ---- Keyboard: "/" focuses global search ----
document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        const s = document.getElementById('z-global-search');
        if (s) { e.preventDefault(); s.focus(); }
    }
});
