/*
 * TaskFlow - shared client logic used on every page.
 *   - api()          : fetch wrapper with graceful error handling
 *   - toast()        : lightweight non-blocking notifications
 *   - Dark mode toggle (persisted to localStorage)
 *   - Quick Links dropdown (add / delete via API)
 */

// -------------------------------------------------------------------
// API helper - POSTs JSON to api.php?action=… and returns the payload.
// Throws on network / server errors so callers can `try/catch`.
// -------------------------------------------------------------------
async function api(action, data = {}) {
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    let payload;
    try {
        payload = await res.json();
    } catch {
        throw new Error('Invalid server response');
    }
    if (!res.ok || !payload.ok) {
        throw new Error(payload.error || `Request failed (${res.status})`);
    }
    return payload;
}

// -------------------------------------------------------------------
// Toast notifications
// -------------------------------------------------------------------
function toast(message, type = 'success') {
    const host = document.getElementById('toast');
    if (!host) return;
    const colors = {
        success: 'bg-green-600',
        error: 'bg-red-600',
        info: 'bg-gray-700',
    };
    const el = document.createElement('div');
    el.className = `${colors[type] || colors.info} text-white text-sm px-4 py-2 rounded-lg shadow-lg opacity-0 transition-opacity`;
    el.textContent = message;
    host.appendChild(el);
    requestAnimationFrame(() => (el.style.opacity = '1'));
    setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 300);
    }, 2600);
}

// -------------------------------------------------------------------
// Dark mode
// -------------------------------------------------------------------
document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const root = document.documentElement;
    root.classList.toggle('dark');
    localStorage.setItem('taskflow-theme', root.classList.contains('dark') ? 'dark' : 'light');
});

// -------------------------------------------------------------------
// Sidebar: Projects submenu toggle (delegated - robust to load timing)
// -------------------------------------------------------------------
document.addEventListener('click', (e) => {
    if (!e.target.closest('#nav-projects-toggle')) return;
    document.getElementById('nav-projects-list')?.classList.toggle('hidden');
    document.getElementById('nav-projects-caret')?.classList.toggle('rotate-90');
});

// -------------------------------------------------------------------
// Sidebar: mobile open/close (overlay below the md breakpoint)
// -------------------------------------------------------------------
function openSidebar() {
    document.getElementById('sidebar')?.classList.remove('-translate-x-full');
    document.getElementById('sidebar-backdrop')?.classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar')?.classList.add('-translate-x-full');
    document.getElementById('sidebar-backdrop')?.classList.add('hidden');
}
document.addEventListener('click', (e) => {
    if (e.target.closest('#sidebar-open')) return openSidebar();
    if (e.target.closest('#sidebar-close') || e.target.closest('#sidebar-backdrop')) return closeSidebar();
    // Tapping a nav link inside the sidebar on mobile should close the overlay.
    if (e.target.closest('#sidebar a')) closeSidebar();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
});

// -------------------------------------------------------------------
// Global topbar search: filters any element carrying [data-search] and
// its wrapper marked [data-searchable]. No reload.
// -------------------------------------------------------------------
document.getElementById('global-search')?.addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('[data-search]').forEach((el) => {
        el.classList.toggle('hidden', q !== '' && !el.dataset.search.includes(q));
    });
    document.querySelectorAll('[data-empty-when-filtered]').forEach((el) => {
        const scope = document.querySelector(el.dataset.emptyWhenFiltered);
        const anyVisible = scope && [...scope.querySelectorAll('[data-search]')].some((n) => !n.classList.contains('hidden'));
        el.classList.toggle('hidden', anyVisible || q === '');
    });
});

// -------------------------------------------------------------------
// Quick Links dropdown
// -------------------------------------------------------------------
(function initQuickLinks() {
    const toggle = document.getElementById('ql-toggle');
    const menu = document.getElementById('ql-menu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        menu.classList.toggle('hidden');
    });
    // Close when clicking outside.
    document.addEventListener('click', (e) => {
        if (!document.getElementById('ql-wrapper').contains(e.target)) {
            menu.classList.add('hidden');
        }
    });

    // Add a link.
    document.getElementById('ql-add')?.addEventListener('click', async () => {
        const title = document.getElementById('ql-title').value.trim();
        const url = document.getElementById('ql-url').value.trim();
        if (!title || !url) return toast('Title and URL required', 'error');
        try {
            const r = await api('link.create', { title, url });
            addLinkRow(r.id, r.title, r.url);
            document.getElementById('ql-title').value = '';
            document.getElementById('ql-url').value = '';
            toast('Link added');
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    // Delete links (event delegation).
    document.getElementById('ql-list')?.addEventListener('click', async (e) => {
        if (!e.target.classList.contains('ql-del')) return;
        const li = e.target.closest('li');
        try {
            await api('link.delete', { id: Number(li.dataset.id) });
            li.remove();
            toast('Link removed');
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    function addLinkRow(id, title, url) {
        const li = document.createElement('li');
        li.className =
            'flex items-center justify-between gap-2 group px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700';
        li.dataset.id = id;
        li.innerHTML = `
            <a href="${url}" target="_blank" rel="noopener"
               class="truncate text-indigo-600 dark:text-indigo-400 hover:underline"></a>
            <button class="ql-del opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500" title="Delete">✕</button>`;
        li.querySelector('a').textContent = title; // set as text to avoid injection
        document.getElementById('ql-list').appendChild(li);
    }
})();
