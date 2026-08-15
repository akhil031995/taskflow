/*
 * TaskFlow - live session monitor.
 *   - Polls api.php?action=sessions.active for the "Active Code Sessions" panel.
 *   - On selection, opens the Live Claude Session drawer and connects an
 *     EventSource to api.php?action=session.stream&task_id=X.
 *   - Routes SSE log events into the Conversation / Code View / Terminal panels,
 *     auto-scrolling the terminal only when the user is already at the bottom.
 *   - EventSource reconnects automatically; the connection dot reflects state.
 *
 * Depends on api()/toast() from app.js.
 */
(function () {
    const panel = document.getElementById('sessions-panel');
    const drawer = document.getElementById('session-drawer');
    if (!panel || !drawer) return; // partial not present

    const listEl = document.getElementById('sessions-list');
    const countEl = document.getElementById('sessions-count');

    let source = null;          // active EventSource
    let currentTaskId = null;   // selected session
    const codeFiles = {};       // file_path -> latest diff text
    let activeCodeTab = null;

    // ---------------- Active sessions polling ----------------
    async function loadSessions() {
        try {
            const r = await api('sessions.active', {});
            renderSessions(r.sessions || []);
        } catch { /* keep last render */ }
    }

    function statusDotHtml(s) {
        const ping = s.pulse
            ? `<span class="animate-ping absolute inline-flex h-full w-full rounded-full ${s.dot} opacity-75"></span>`
            : '';
        return `<span class="relative flex h-2 w-2">${ping}<span class="relative inline-flex rounded-full h-2 w-2 ${s.dot}"></span></span>`;
    }

    function renderSessions(sessions) {
        countEl.textContent = sessions.length;
        if (sessions.length === 0) {
            listEl.innerHTML = '<li class="px-2 py-3 text-xs text-slate-500 text-center">No active sessions</li>';
            return;
        }
        listEl.innerHTML = '';
        sessions.forEach((s) => {
            const li = document.createElement('li');
            const selected = s.id === currentTaskId;
            li.className = 'rounded-lg px-3 py-2 cursor-pointer border ' +
                (selected
                    ? 'bg-indigo-600/20 border-indigo-500/50'
                    : 'bg-slate-800/50 border-transparent hover:bg-slate-800');
            const title = document.createElement('p');
            title.className = 'text-xs font-medium truncate';
            title.textContent = `${s.key} - ${s.title}`;
            const meta = document.createElement('div');
            meta.className = 'flex items-center gap-1.5 mt-1';
            meta.innerHTML = `${statusDotHtml(s)}<span class="text-[10px] font-semibold text-slate-400">${escapeHtml(s.label)}</span>` +
                (selected ? '<span class="ml-auto text-[9px] font-semibold text-indigo-300">SELECTED</span>' : '');
            li.appendChild(title);
            li.appendChild(meta);
            li.addEventListener('click', () => selectSession(s.id, `${s.key} - ${s.title}`, s.label, s.dot));
            listEl.appendChild(li);
        });
    }

    // ---------------- Panel collapse ----------------
    document.getElementById('sessions-toggle')?.addEventListener('click', () => {
        listEl.classList.toggle('hidden');
        document.getElementById('sessions-caret')?.classList.toggle('rotate-180');
    });

    // ---------------- Session selection + drawer ----------------
    function selectSession(taskId, title, label, dot) {
        currentTaskId = taskId;
        document.getElementById('drawer-title').textContent = title;
        const badge = document.getElementById('drawer-badge');
        badge.textContent = label || 'RUNNING';

        // Reset panels.
        document.getElementById('conv-panel').innerHTML = '<p class="text-slate-500">Connecting…</p>';
        document.getElementById('term-panel').innerHTML = '';
        document.getElementById('code-tabs').innerHTML = '';
        document.getElementById('code-body').innerHTML = '<span class="text-slate-500">No file changes yet.</span>';
        for (const k in codeFiles) delete codeFiles[k];
        activeCodeTab = null;

        openDrawer();
        highlightCard(taskId);
        connectStream(taskId);
        loadSessions(); // refresh SELECTED marker
    }
    window.selectSession = selectSession;

    function openDrawer() { drawer.classList.remove('translate-x-full'); }
    function closeDrawer() {
        drawer.classList.add('translate-x-full');
        if (source) { source.close(); source = null; }
        clearHighlight();
        currentTaskId = null;
        loadSessions();
    }
    document.getElementById('drawer-close').addEventListener('click', closeDrawer);

    // ---------------- Kanban card highlight ----------------
    function highlightCard(taskId) {
        clearHighlight();
        const card = document.querySelector(`.task-card[data-id="${taskId}"]`);
        if (!card) return;
        card.classList.add('ring-2', 'ring-indigo-500', 'session-selected');
        if (!card.querySelector('.session-selected-badge')) {
            const b = document.createElement('div');
            b.className = 'session-selected-badge absolute -top-2 left-3 text-[9px] font-bold px-2 py-0.5 rounded-full bg-indigo-600 text-white';
            b.textContent = 'SESSION SELECTED';
            card.appendChild(b);
        }
    }
    function clearHighlight() {
        document.querySelectorAll('.session-selected').forEach((c) => {
            c.classList.remove('ring-2', 'ring-indigo-500', 'session-selected');
            c.querySelector('.session-selected-badge')?.remove();
        });
    }

    // ---------------- SSE stream ----------------
    function connectStream(taskId) {
        if (source) source.close();
        setConn('connecting');
        source = new EventSource(`api.php?action=session.stream&task_id=${encodeURIComponent(taskId)}`);
        source.addEventListener('open', () => setConn('open'));
        source.addEventListener('log', (e) => {
            try { routeLog(JSON.parse(e.data)); } catch { /* ignore bad frame */ }
        });
        source.addEventListener('error', () => setConn('error')); // browser auto-reconnects
    }

    function setConn(state) {
        const dot = document.getElementById('drawer-conn');
        if (!dot) return;
        dot.className = 'text-[10px] ' + (
            state === 'open' ? 'text-emerald-400' : state === 'error' ? 'text-rose-400' : 'text-amber-400'
        );
        dot.title = `Stream: ${state}`;
    }

    function routeLog(log) {
        switch (log.log_type) {
            case 'thought':
            case 'status':
                appendConversation(log);
                break;
            case 'terminal':
                appendTerminal(log);
                break;
            case 'code_diff':
                appendDiff(log);
                break;
        }
    }

    // Prefer the server's IST time string (log.t); fall back to parsing.
    function logTime(log) {
        if (log && log.t) return log.t;
        const d = new Date(log && log.created_at);
        return isNaN(d) ? '' : d.toTimeString().slice(0, 8);
    }

    function appendConversation(log) {
        const host = document.getElementById('conv-panel');
        if (host.dataset.empty !== '0') { host.innerHTML = ''; host.dataset.empty = '0'; }
        const wrap = document.createElement('div');
        const isStatus = log.log_type === 'status';
        wrap.className = 'rounded-lg p-2 ' + (isStatus
            ? 'bg-slate-800/60 border border-slate-700/60'
            : 'bg-indigo-600/10 border border-indigo-500/20');
        const head = document.createElement('div');
        head.className = 'flex items-center justify-between mb-0.5';
        head.innerHTML = `<span class="text-[10px] font-semibold ${isStatus ? 'text-slate-400' : 'text-indigo-300'}">${isStatus ? 'STATUS' : 'AI'}</span><span class="text-[10px] text-slate-500 font-mono">${logTime(log)}</span>`;
        const body = document.createElement('p');
        body.className = 'text-slate-300 whitespace-pre-wrap break-words';
        body.textContent = log.content;
        wrap.appendChild(head);
        wrap.appendChild(body);
        host.appendChild(wrap);
        host.scrollTop = host.scrollHeight;
    }

    function appendTerminal(log) {
        const host = document.getElementById('term-panel');
        const atBottom = host.scrollHeight - host.scrollTop - host.clientHeight < 24;
        const line = document.createElement('div');
        line.className = 'whitespace-pre-wrap break-words';
        const t = logTime(log);
        line.textContent = (t ? `[${t}] ` : '') + log.content;
        host.appendChild(line);
        if (atBottom) host.scrollTop = host.scrollHeight; // only auto-scroll if user is at bottom
    }

    function appendDiff(log) {
        const file = log.file_path || 'changes';
        codeFiles[file] = log.content;
        renderCodeTabs();
        showCodeFile(file);
    }

    function renderCodeTabs() {
        const tabs = document.getElementById('code-tabs');
        tabs.innerHTML = '';
        Object.keys(codeFiles).forEach((file) => {
            const b = document.createElement('button');
            b.className = 'text-[11px] font-mono px-2 py-1 rounded-t border-b-2 ' +
                (file === activeCodeTab ? 'border-indigo-500 text-white' : 'border-transparent text-slate-400 hover:text-white');
            b.textContent = file.split('/').pop();
            b.title = file;
            b.addEventListener('click', () => showCodeFile(file));
            tabs.appendChild(b);
        });
    }

    function showCodeFile(file) {
        activeCodeTab = file;
        renderCodeTabs();
        const body = document.getElementById('code-body');
        body.innerHTML = '';
        (codeFiles[file] || '').split('\n').forEach((ln) => {
            const span = document.createElement('div');
            let cls = 'text-slate-300';
            if (ln.startsWith('+') && !ln.startsWith('+++')) cls = 'text-emerald-400 bg-emerald-500/5';
            else if (ln.startsWith('-') && !ln.startsWith('---')) cls = 'text-rose-400 bg-rose-500/5';
            else if (ln.startsWith('@@')) cls = 'text-indigo-400';
            span.className = cls;
            span.textContent = ln || ' ';
            body.appendChild(span);
        });
    }

    function escapeHtml(v) {
        return String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    }

    // ---------------- Boot ----------------
    loadSessions();
    setInterval(loadSessions, 5000);
})();
