/*
 * TaskFlow - project view logic.
 *   - View switching (Board / Notes)
 *   - Kanban drag-and-drop via SortableJS -> AJAX status/position updates
 *   - Task create / edit / delete modal
 *   - Tabbed notes with a Quill editor and debounced AJAX auto-save
 *
 * Depends on globals from project.php: PROJECT_ID, PRIORITY_CLASSES
 * and shared helpers from app.js: api(), toast().
 */

// ===================================================================
// View switching (Board <-> Notes)
// ===================================================================
document.querySelectorAll('.view-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.view-tab').forEach((t) => {
            t.classList.remove('border-indigo-600');
            t.classList.add('border-transparent', 'text-gray-500');
        });
        tab.classList.add('border-indigo-600');
        tab.classList.remove('border-transparent', 'text-gray-500');

        const view = tab.dataset.view;
        document.getElementById('view-board').classList.toggle('hidden', view !== 'board');
        document.getElementById('view-notes').classList.toggle('hidden', view !== 'notes');
        document.getElementById('view-standards').classList.toggle('hidden', view !== 'standards');
        document.getElementById('view-dod').classList.toggle('hidden', view !== 'dod');
        if (view === 'standards') loadStandards();
        if (view === 'dod') loadDodGates();
    });
});

// ===================================================================
// Standards (org baseline + per-project override)
// ===================================================================
let standardsLoaded = false;

async function loadStandards() {
    if (standardsLoaded) return;
    try {
        const { baseline, override, effective } = await api('project.standards.get', { project_id: PROJECT_ID });
        document.getElementById('std-baseline').textContent = baseline || '(no org baseline set)';
        document.getElementById('std-override').value = override;
        document.getElementById('std-effective').textContent = effective || '(nothing resolved yet)';
        standardsLoaded = true;
    } catch (err) {
        toast(err.message, 'error');
    }
}

document.getElementById('std-save').addEventListener('click', async () => {
    try {
        const override = document.getElementById('std-override').value;
        const { effective } = await api('project.standards.save', { project_id: PROJECT_ID, override });
        document.getElementById('std-effective').textContent = effective || '(nothing resolved yet)';
        toast('Standards saved');
    } catch (err) {
        toast(err.message, 'error');
    }
});

// ===================================================================
// DoD gates (per-project lint/test/build commands + criteria override)
// ===================================================================
let dodLoaded = false;

async function loadDodGates() {
    if (dodLoaded) return;
    try {
        const dod = await api('project.dod.get', { project_id: PROJECT_ID });
        document.getElementById('dod-lint').value = dod.lint_cmd || '';
        document.getElementById('dod-test').value = dod.test_cmd || '';
        document.getElementById('dod-build').value = dod.build_cmd || '';
        document.getElementById('dod-criteria').value = dod.criteria_md || '';
        document.getElementById('dod-criteria').placeholder = dod.default_criteria || '';
        dodLoaded = true;
    } catch (err) {
        toast(err.message, 'error');
    }
}

document.getElementById('dod-save').addEventListener('click', async () => {
    try {
        await api('project.dod.save', {
            project_id: PROJECT_ID,
            lint_cmd: document.getElementById('dod-lint').value,
            test_cmd: document.getElementById('dod-test').value,
            build_cmd: document.getElementById('dod-build').value,
            criteria_md: document.getElementById('dod-criteria').value,
        });
        toast('DoD gates saved');
    } catch (err) {
        toast(err.message, 'error');
    }
});

// ===================================================================
// Board card density (Comfortable / Compact), persisted to localStorage
// ===================================================================
const BOARD_VIEW_KEY = 'taskflow-board-view';
const boardEl = document.getElementById('view-board');

function applyBoardView(mode) {
    boardEl.classList.toggle('compact-mode', mode === 'compact');
}

const compactSwitch = document.getElementById('board-view-compact');
const savedBoardView = localStorage.getItem(BOARD_VIEW_KEY) === 'compact' ? 'compact' : 'comfortable';
if (compactSwitch) compactSwitch.checked = savedBoardView === 'compact';
applyBoardView(savedBoardView);

compactSwitch?.addEventListener('change', () => {
    const mode = compactSwitch.checked ? 'compact' : 'comfortable';
    localStorage.setItem(BOARD_VIEW_KEY, mode);
    applyBoardView(mode);
});

// ===================================================================
// Kanban drag-and-drop
// ===================================================================
document.querySelectorAll('.kanban-col').forEach((col) => {
    new Sortable(col, {
        group: 'kanban',           // allow moving cards between columns
        animation: 150,
        ghostClass: 'opacity-40',
        // AI-locked cards (agent mid-run) cannot be dragged.
        filter: '.task-locked',
        // On touch devices, require a long press before a drag starts so a
        // normal finger swipe still scrolls the page instead of yanking a
        // card. Mouse users are unaffected (delayOnTouchOnly).
        delay: 200,
        delayOnTouchOnly: true,
        touchStartThreshold: 5,
        onEnd: async (evt) => {
            const card = evt.item;
            const destCol = evt.to;
            const newStatus = destCol.dataset.status;
            const id = Number(card.dataset.id);

            // Capture the destination column's ordering so the server can persist it.
            const order = [...destCol.querySelectorAll('.task-card')].map((c) => Number(c.dataset.id));

            try {
                await api('task.move', { id, status: newStatus, order });
                card.dataset.status = newStatus;
                refreshColumnCounts();
                if (evt.from !== evt.to) toast('Task moved');
            } catch (err) {
                toast(err.message, 'error');
                // Roll back the visual move on failure.
                evt.from.insertBefore(card, evt.from.children[evt.oldIndex] || null);
                refreshColumnCounts();
            }
        },
    });
});

function refreshColumnCounts() {
    document.querySelectorAll('.kanban-col').forEach((col) => {
        const badge = col.previousElementSibling.querySelector('span');
        if (badge) badge.textContent = col.querySelectorAll('.task-card').length;
    });
}

// ===================================================================
// Task modal (create / edit / delete)
// ===================================================================
const taskModal = document.getElementById('task-modal');
const fields = {
    id: document.getElementById('tf-id'),
    title: document.getElementById('tf-title'),
    desc: document.getElementById('tf-desc'),
    status: document.getElementById('tf-status'),
    priority: document.getElementById('tf-priority'),
    type: document.getElementById('tf-type'),
    acceptance: document.getElementById('tf-acceptance'),
};

function openTaskModal(mode, data = {}) {
    document.getElementById('task-modal-title').textContent = mode === 'edit' ? 'Edit Task' : 'Add Task';
    fields.id.value = data.id || '';
    fields.title.value = data.title || '';
    fields.desc.value = data.description || '';
    fields.status.value = data.status || 'pending';
    fields.priority.value = String(data.priority || 2);
    fields.type.value = data.task_type || 'feature';
    fields.acceptance.value = data.acceptance || '';
    document.getElementById('tf-delete').classList.toggle('hidden', mode !== 'edit');
    taskModal.classList.remove('hidden');
    fields.title.focus();
}
function closeTaskModal() { taskModal.classList.add('hidden'); }

document.getElementById('add-task-btn').addEventListener('click', () => openTaskModal('create'));
document.getElementById('tf-cancel').addEventListener('click', closeTaskModal);
taskModal.addEventListener('click', (e) => { if (e.target === taskModal) closeTaskModal(); });

// Card clicks: pencil -> edit modal; title / Details -> detail modal.
document.getElementById('view-board').addEventListener('click', (e) => {
    const card = e.target.closest('.task-card');
    if (!card) return;
    if (e.target.classList.contains('edit-task')) {
        openTaskModal('edit', {
            id: card.dataset.id,
            title: card.dataset.title,
            description: card.dataset.description,
            status: card.dataset.status,
            priority: card.dataset.priority,
            task_type: card.dataset.taskType,
            acceptance: card.dataset.acceptance,
        });
    } else if (e.target.classList.contains('details-task') || e.target.classList.contains('task-title')) {
        openDetailModal(Number(card.dataset.id));
    }
});

// ===================================================================
// Task detail modal (read-only, full lifecycle)
// ===================================================================
const detailModal = document.getElementById('detail-modal');
let detailTask = null;

function fmtTime(v) { return v ? String(v) : '—'; }

async function openDetailModal(id) {
    try {
        const r = await api('task.get', { id });
        detailTask = r.task;
        const t = r.task;

        document.getElementById('dm-key').textContent = r.key;
        document.getElementById('dm-title').textContent = t.title;
        document.getElementById('dm-desc').textContent = t.description || '—';

        // Status badges
        const statusMap = { pending: 'Pending', in_progress: 'In Progress', on_hold: 'On Hold', completed: 'Completed' };
        const st = document.getElementById('dm-status');
        st.innerHTML = '';
        const pill = (text, cls) => { const s = document.createElement('span'); s.className = `text-[11px] font-semibold px-2 py-0.5 rounded-full ${cls}`; s.textContent = text; return s; };
        st.appendChild(pill(statusMap[t.status] || t.status, 'bg-slate-500/15 text-slate-300 ring-1 ring-slate-500/30'));
        st.appendChild(pill('AI: ' + t.ai_execution_status, 'bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-500/30'));
        st.appendChild(pill(r.priority_label, r.priority_class));
        st.appendChild(pill(r.type_label, 'bg-slate-500/15 text-slate-300 ring-1 ring-slate-500/30'));
        st.appendChild(pill(t.created_by === 'ai' ? '🤖 AI-drafted' : '👤 Human', 'bg-purple-500/15 text-purple-300 ring-1 ring-purple-500/30'));

        // Timeline
        const rows = [
            ['Created', t.created_at],
            ['AI picked up', t.ai_started_at || t.ai_locked_at],
            ['Last updated', t.updated_at],
            ['Completed', t.ai_completed_at],
        ];
        const tl = document.getElementById('dm-timeline');
        tl.innerHTML = '';
        rows.forEach(([label, val]) => {
            const dt = document.createElement('dt'); dt.className = 'text-slate-500'; dt.textContent = label;
            const dd = document.createElement('dd'); dd.className = 'font-mono text-slate-700 dark:text-slate-300'; dd.textContent = fmtTime(val);
            tl.appendChild(dt); tl.appendChild(dd);
        });

        // Requirements (read-only checklist)
        const cr = document.getElementById('dm-criteria');
        cr.innerHTML = '';
        if (r.criteria.length === 0) { cr.innerHTML = '<li class="text-slate-500">None</li>'; }
        r.criteria.forEach((c) => {
            const li = document.createElement('li'); li.className = 'flex items-start gap-2';
            const box = document.createElement('span'); box.textContent = c.checked ? '☑' : '☐'; box.className = c.checked ? 'text-emerald-400' : 'text-slate-500';
            const sp = document.createElement('span'); sp.textContent = c.text; sp.className = c.checked ? 'line-through text-slate-400' : '';
            li.appendChild(box); li.appendChild(sp); cr.appendChild(li);
        });

        // Comments (left)
        const cm = document.getElementById('dm-comments');
        cm.innerHTML = '';
        if (r.comments.length === 0) { cm.innerHTML = '<li class="text-slate-500 text-sm">No comments yet.</li>'; }
        r.comments.forEach((c) => {
            const li = document.createElement('li');
            li.className = 'rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 p-3';
            const head = document.createElement('p'); head.className = 'text-[11px] font-mono text-slate-400 mb-1'; head.textContent = c.datetime || c.time || '';
            const body = document.createElement('p'); body.className = 'text-sm text-slate-700 dark:text-slate-200 whitespace-pre-wrap'; body.textContent = c.text;
            li.appendChild(head); li.appendChild(body); cm.appendChild(li);
        });

        // Human comments (right above the agent log)
        renderHumanComments(r.human_comments || []);
        document.getElementById('dm-comment-author').value = localStorage.getItem('tf-comment-author') || '';

        // Workspace
        const ws = document.getElementById('dm-workspace');
        ws.innerHTML = '';
        if (r.project) {
            const name = document.createElement('p'); name.className = 'text-slate-600 dark:text-slate-300'; name.textContent = '📦 ' + r.project.name; ws.appendChild(name);
            if (r.project.folder_path) { const f = document.createElement('p'); f.className = 'font-mono text-xs text-slate-500 break-all'; f.textContent = '📁 ' + r.project.folder_path; ws.appendChild(f); }
            if (r.project.access_url) { const a = document.createElement('a'); a.href = r.project.access_url; a.target = '_blank'; a.rel = 'noopener'; a.className = 'text-indigo-500 dark:text-indigo-400 hover:underline text-xs'; a.textContent = '🔗 ' + r.project.access_url; ws.appendChild(a); }
        }

        detailModal.classList.remove('hidden');
    } catch (err) {
        toast(err.message, 'error');
    }
}

function renderHumanComments(comments) {
    const hc = document.getElementById('dm-human-comments');
    hc.innerHTML = '';
    if (comments.length === 0) { hc.innerHTML = '<li class="text-slate-500 text-sm">No comments yet.</li>'; return; }
    comments.forEach((c) => {
        const li = document.createElement('li');
        li.className = 'rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 p-3';
        const head = document.createElement('p'); head.className = 'text-[11px] font-mono text-slate-400 mb-1';
        head.textContent = `${c.author} · ${c.datetime || c.time || ''}`;
        const body = document.createElement('p'); body.className = 'text-sm text-slate-700 dark:text-slate-200 whitespace-pre-wrap'; body.textContent = c.text;
        li.appendChild(head); li.appendChild(body); hc.appendChild(li);
    });
}

document.getElementById('dm-comment-add').addEventListener('click', async () => {
    if (!detailTask) return;
    const authorEl = document.getElementById('dm-comment-author');
    const textEl = document.getElementById('dm-comment-text');
    const author = authorEl.value.trim();
    const comment_text = textEl.value.trim();
    if (!comment_text) return toast('Comment text required', 'error');
    try {
        const r = await api('task.comment.create', { id: detailTask.id, author, comment_text });
        renderHumanComments(r.comments);
        textEl.value = '';
        if (author) localStorage.setItem('tf-comment-author', author);
        toast('Comment added', 'success');
    } catch (err) {
        toast(err.message, 'error');
    }
});

function closeDetailModal() { detailModal.classList.add('hidden'); }
document.getElementById('dm-close').addEventListener('click', closeDetailModal);
detailModal.addEventListener('click', (e) => { if (e.target === detailModal) closeDetailModal(); });
document.getElementById('dm-edit').addEventListener('click', () => {
    if (!detailTask) return;
    closeDetailModal();
    openTaskModal('edit', {
        id: detailTask.id,
        title: detailTask.title,
        description: detailTask.description,
        status: detailTask.status,
        priority: detailTask.priority,
        task_type: detailTask.task_type,
        acceptance: detailTask.acceptance_criteria,
    });
});

// Toggle an acceptance-criteria checkbox and persist the whole checklist.
document.getElementById('view-board').addEventListener('change', async (e) => {
    if (!e.target.classList.contains('criteria-box')) return;
    const card = e.target.closest('.task-card');
    const lines = [...card.querySelectorAll('.criteria-list li')].map((li) => {
        const box = li.querySelector('.criteria-box');
        const text = li.querySelector('span').textContent;
        return `- [${box.checked ? 'x' : ' '}] ${text}`;
    });
    const text = lines.join('\n');
    // Optimistic strike-through.
    const span = e.target.closest('li').querySelector('span');
    span.classList.toggle('line-through', e.target.checked);
    span.classList.toggle('text-slate-400', e.target.checked);
    try {
        await api('task.criteria', { id: Number(card.dataset.id), acceptance_criteria: text });
        card.dataset.acceptance = text;
    } catch (err) {
        toast(err.message, 'error');
        e.target.checked = !e.target.checked; // revert
    }
});

// Save (create or update).
document.getElementById('tf-save').addEventListener('click', async () => {
    const payload = {
        title: fields.title.value.trim(),
        description: fields.desc.value.trim(),
        status: fields.status.value,
        priority: Number(fields.priority.value),
        task_type: fields.type.value,
        acceptance_criteria: fields.acceptance.value.trim(),
    };
    if (!payload.title) return toast('Title required', 'error');

    try {
        if (fields.id.value) {
            payload.id = Number(fields.id.value);
            await api('task.update', payload);
            toast('Task updated');
        } else {
            payload.project_id = PROJECT_ID;
            await api('task.create', payload);
            toast('Task created');
        }
        // Reload so the card re-renders with the correct column, strip color,
        // and priority / type / AI badges.
        window.location.reload();
    } catch (err) {
        toast(err.message, 'error');
    }
});

// Delete from the modal.
document.getElementById('tf-delete').addEventListener('click', async () => {
    const id = Number(fields.id.value);
    if (!id || !confirm('Delete this task?')) return;
    try {
        await api('task.delete', { id });
        document.querySelector(`.task-card[data-id="${id}"]`)?.remove();
        closeTaskModal();
        refreshColumnCounts();
        toast('Task deleted');
    } catch (err) {
        toast(err.message, 'error');
    }
});

// ===================================================================
// Notes: Quill editor + tabs + debounced auto-save
// ===================================================================
(function initNotes() {
    const notes = JSON.parse(document.getElementById('notes-data').textContent || '[]');
    const tabsHost = document.getElementById('note-tabs');
    const statusEl = document.getElementById('note-status');
    const renameBtn = document.getElementById('rename-note');
    const deleteBtn = document.getElementById('delete-note');

    const quill = new Quill('#editor', {
        theme: 'snow',
        placeholder: 'Start writing…',
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', 'code-block', 'link'],
                ['clean'],
            ],
        },
    });

    let activeId = null;
    let saveTimer = null;
    let suppressChange = false; // don't auto-save while programmatically loading content

    function noteById(id) { return notes.find((n) => n.id === id); }

    function selectNote(id) {
        const note = noteById(id);
        if (!note) return;
        activeId = id;
        suppressChange = true;
        quill.root.innerHTML = note.content || '';
        suppressChange = false;

        // Highlight the active tab.
        tabsHost.querySelectorAll('.note-tab').forEach((t) => {
            const on = Number(t.dataset.id) === id;
            t.classList.toggle('border-indigo-600', on);
            t.classList.toggle('font-medium', on);
            t.classList.toggle('border-transparent', !on);
            t.classList.toggle('text-gray-500', !on);
        });
        renameBtn.classList.remove('hidden');
        deleteBtn.classList.remove('hidden');
        statusEl.textContent = 'All changes saved';
    }

    // Debounced auto-save (fires ~1.2s after the user stops typing).
    quill.on('text-change', () => {
        if (suppressChange || activeId === null) return;
        statusEl.textContent = 'Editing…';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveActiveNote, 1200);
    });

    async function saveActiveNote() {
        if (activeId === null) return;
        const content = quill.root.innerHTML;
        try {
            const r = await api('note.save', { id: activeId, content });
            const note = noteById(activeId);
            if (note) note.content = content;
            statusEl.textContent = 'Saved · ' + new Date(r.saved_at).toLocaleTimeString();
        } catch (err) {
            statusEl.textContent = '⚠ Save failed - retrying…';
            // Retry once shortly after a transient failure.
            clearTimeout(saveTimer);
            saveTimer = setTimeout(saveActiveNote, 3000);
        }
    }

    // Tab clicks (event delegation).
    tabsHost.addEventListener('click', (e) => {
        const tab = e.target.closest('.note-tab');
        if (tab) selectNote(Number(tab.dataset.id));
    });

    // Add a note tab.
    document.getElementById('add-note').addEventListener('click', async () => {
        const title = prompt('Note title:', 'New Note');
        if (title === null) return;
        try {
            const r = await api('note.create', { project_id: PROJECT_ID, title: title.trim() || 'New Note' });
            notes.push({ id: r.id, title: r.title, content: '' });
            const btn = document.createElement('button');
            btn.className = 'note-tab whitespace-nowrap px-3 py-2 text-sm border-b-2 border-transparent text-gray-500';
            btn.dataset.id = r.id;
            btn.textContent = r.title;
            tabsHost.insertBefore(btn, document.getElementById('add-note'));
            selectNote(r.id);
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    // Rename the active note.
    renameBtn.addEventListener('click', async () => {
        if (activeId === null) return;
        const note = noteById(activeId);
        const title = prompt('Rename note:', note.title);
        if (!title || !title.trim()) return;
        try {
            await api('note.rename', { id: activeId, title: title.trim() });
            note.title = title.trim();
            tabsHost.querySelector(`.note-tab[data-id="${activeId}"]`).textContent = note.title;
            toast('Note renamed');
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    // Delete the active note.
    deleteBtn.addEventListener('click', async () => {
        if (activeId === null || !confirm('Delete this note?')) return;
        const id = activeId;
        try {
            await api('note.delete', { id });
            const idx = notes.findIndex((n) => n.id === id);
            if (idx > -1) notes.splice(idx, 1);
            tabsHost.querySelector(`.note-tab[data-id="${id}"]`)?.remove();
            activeId = null;
            quill.setText('');
            renameBtn.classList.add('hidden');
            deleteBtn.classList.add('hidden');
            if (notes.length) selectNote(notes[0].id);
            else statusEl.textContent = 'Select or create a note';
            toast('Note deleted');
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    // Auto-select the first note on load.
    if (notes.length) selectNote(notes[0].id);
})();
