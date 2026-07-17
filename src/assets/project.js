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
    });
});

// ===================================================================
// Kanban drag-and-drop
// ===================================================================
document.querySelectorAll('.kanban-col').forEach((col) => {
    new Sortable(col, {
        group: 'kanban',           // allow moving cards between columns
        animation: 150,
        ghostClass: 'opacity-40',
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
};

function openTaskModal(mode, data = {}) {
    document.getElementById('task-modal-title').textContent = mode === 'edit' ? 'Edit Task' : 'Add Task';
    fields.id.value = data.id || '';
    fields.title.value = data.title || '';
    fields.desc.value = data.description || '';
    fields.status.value = data.status || 'pending';
    fields.priority.value = data.priority || 'medium';
    document.getElementById('tf-delete').classList.toggle('hidden', mode !== 'edit');
    taskModal.classList.remove('hidden');
    fields.title.focus();
}
function closeTaskModal() { taskModal.classList.add('hidden'); }

document.getElementById('add-task-btn').addEventListener('click', () => openTaskModal('create'));
document.getElementById('tf-cancel').addEventListener('click', closeTaskModal);
taskModal.addEventListener('click', (e) => { if (e.target === taskModal) closeTaskModal(); });

// Open editor from a card's pencil button.
document.getElementById('view-board').addEventListener('click', (e) => {
    if (!e.target.classList.contains('edit-task')) return;
    const card = e.target.closest('.task-card');
    openTaskModal('edit', {
        id: card.dataset.id,
        title: card.dataset.title,
        description: card.dataset.description,
        status: card.dataset.status,
        priority: card.dataset.priority,
    });
});

// Save (create or update).
document.getElementById('tf-save').addEventListener('click', async () => {
    const payload = {
        title: fields.title.value.trim(),
        description: fields.desc.value.trim(),
        status: fields.status.value,
        priority: fields.priority.value,
    };
    if (!payload.title) return toast('Title required', 'error');

    try {
        if (fields.id.value) {
            payload.id = Number(fields.id.value);
            await api('task.update', payload);
            toast('Task updated');
        } else {
            payload.project_id = PROJECT_ID;
            const r = await api('task.create', payload);
            payload.id = r.id;
            addCardToBoard(payload);
            toast('Task created');
        }
        // Simplest reliable way to reflect edits (status/priority/text) is a reload.
        if (fields.id.value) {
            window.location.reload();
            return;
        }
        closeTaskModal();
        refreshColumnCounts();
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

/** Build and insert a new card into the correct column (client render). */
function addCardToBoard(task) {
    const col = document.querySelector(`.kanban-col[data-status="${task.status}"]`);
    if (!col) return;
    const card = document.createElement('div');
    card.className =
        'task-card group relative bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm cursor-grab active:cursor-grabbing overflow-hidden flex';
    card.dataset.id = task.id;
    card.dataset.title = task.title;
    card.dataset.description = task.description || '';
    card.dataset.priority = task.priority;
    card.dataset.status = task.status;
    card.innerHTML = `
        <span class="w-1.5 shrink-0 ${PRIORITY_CLASSES[task.priority]}"></span>
        <div class="p-3 flex-1 min-w-0">
            <p class="font-medium text-sm truncate"></p>
            <p class="desc text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"></p>
        </div>
        <button class="edit-task opacity-0 group-hover:opacity-100 absolute top-2 right-2 text-gray-400 hover:text-indigo-600 text-xs">✎</button>`;
    card.querySelector('.font-medium').textContent = task.title;
    const desc = card.querySelector('.desc');
    if (task.description) desc.textContent = task.description; else desc.remove();
    col.appendChild(card);
}

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
