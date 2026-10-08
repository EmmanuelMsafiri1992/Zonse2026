import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';
import { passkeyLogin, passkeyRegister } from './passkeys';

window.bootstrap = bootstrap;
window.Alpine = Alpine;

// ---- Sidebar state (collapsed on desktop / open on mobile) ----
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
        if (window.confirm(message || 'Are you sure?')) form.submit();
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

Alpine.start();

// ---- Enable Bootstrap tooltips / popovers ----
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));

// ---- Flash toasts rendered by the server ----
document.querySelectorAll('[data-flash]').forEach((el) => {
    window.zonseo.toast(el.dataset.flash, el.dataset.flashType || 'success');
    el.remove();
});

// ---- Keyboard: "/" focuses global search ----
document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        const s = document.getElementById('z-global-search');
        if (s) { e.preventDefault(); s.focus(); }
    }
});
