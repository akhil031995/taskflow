<?php
/**
 * Dashboard / home page.
 *   - Stat widgets (projects, tasks, completion %)
 *   - Unified "In Progress" feed across all projects (with live Quick Search)
 *   - Project Hub grid (+ create/delete project)
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle    = 'Dashboard';
$stats        = get_stats();
$projects     = get_projects();
$inProgress   = get_in_progress_tasks();

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== STAT WIDGETS ===================== -->
<section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total Projects</p>
        <p class="text-3xl font-bold mt-1"><?= (int) $stats['projects'] ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total Tasks</p>
        <p class="text-3xl font-bold mt-1"><?= (int) $stats['tasks'] ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-500 dark:text-gray-400">Completion</p>
        <div class="flex items-center gap-3 mt-1">
            <p class="text-3xl font-bold"><?= (int) $stats['completion'] ?>%</p>
            <div class="flex-1 h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                <div class="h-full bg-green-500" style="width: <?= (int) $stats['completion'] ?>%"></div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== IN PROGRESS FEED ===================== -->
<section class="mb-10">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <h2 class="text-xl font-semibold">In Progress</h2>
        <input id="quick-search" type="search" placeholder="🔍 Quick search tasks…"
            class="w-full sm:w-72 px-3 py-2 rounded-lg text-sm border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800">
    </div>

    <div id="inprogress-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php if (empty($inProgress)): ?>
            <p class="text-gray-500 dark:text-gray-400 col-span-full">Nothing in progress right now. 🎉</p>
        <?php endif; ?>
        <?php foreach ($inProgress as $task): ?>
            <a href="project.php?id=<?= (int) $task['project_id'] ?>"
               class="task-item block bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition overflow-hidden"
               data-search="<?= e(strtolower($task['title'] . ' ' . $task['description'] . ' ' . $task['project_name'])) ?>">
                <div class="flex">
                    <span class="w-1.5 <?= PRIORITY_COLORS[$task['priority']] ?>"></span>
                    <div class="p-4">
                        <p class="text-xs font-medium mb-1" style="color: <?= e($task['project_color']) ?>">
                            <?= e($task['project_name']) ?>
                        </p>
                        <p class="font-semibold"><?= e($task['title']) ?></p>
                        <?php if (!empty($task['description'])): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">
                                <?= e($task['description']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <p id="search-empty" class="hidden text-gray-500 dark:text-gray-400 mt-2">No matching tasks.</p>
</section>

<!-- ===================== PROJECT HUB ===================== -->
<section>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold">Project Hub</h2>
        <button id="new-project-btn"
            class="px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">
            + New Project
        </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($projects as $p):
            $ptasks   = get_tasks_for_project((int) $p['id']);
            $total    = count($ptasks);
            $done     = count(array_filter($ptasks, fn ($t) => $t['status'] === 'completed'));
        ?>
            <div class="group relative bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition">
                <a href="project.php?id=<?= (int) $p['id'] ?>" class="block p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="h-3 w-3 rounded-full" style="background: <?= e($p['color']) ?>"></span>
                        <h3 class="font-semibold truncate"><?= e($p['name']) ?></h3>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2 min-h-[2.5rem]">
                        <?= e($p['description']) ?>
                    </p>
                    <div class="mt-4 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span><?= $total ?> tasks</span>
                        <span><?= $done ?>/<?= $total ?> done</span>
                    </div>
                </a>
                <button class="delete-project absolute top-3 right-3 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500"
                        data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" title="Delete project">🗑</button>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===================== NEW PROJECT MODAL ===================== -->
<div id="project-modal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl w-full max-w-md p-6 shadow-xl">
        <h3 class="text-lg font-semibold mb-4">New Project</h3>
        <div class="space-y-3">
            <input id="np-name" type="text" placeholder="Project name"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent">
            <textarea id="np-desc" rows="3" placeholder="Description (optional)"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent"></textarea>
            <label class="flex items-center gap-2 text-sm">
                Accent color <input id="np-color" type="color" value="#6366f1" class="h-8 w-12 rounded">
            </label>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button id="np-cancel" class="px-4 py-2 rounded-lg text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Cancel</button>
            <button id="np-save" class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white hover:bg-indigo-700">Create</button>
        </div>
    </div>
</div>

<script>
// ---- Quick Search: filter the In Progress feed live (no reload) ----
const search = document.getElementById('quick-search');
const emptyMsg = document.getElementById('search-empty');
search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#inprogress-list .task-item').forEach((el) => {
        const match = el.dataset.search.includes(q);
        el.classList.toggle('hidden', !match);
        if (match) visible++;
    });
    emptyMsg.classList.toggle('hidden', visible !== 0 || q === '');
});

// ---- New Project modal ----
const modal = document.getElementById('project-modal');
document.getElementById('new-project-btn').addEventListener('click', () => modal.classList.remove('hidden'));
document.getElementById('np-cancel').addEventListener('click', () => modal.classList.add('hidden'));
modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

document.getElementById('np-save').addEventListener('click', async () => {
    const name = document.getElementById('np-name').value.trim();
    if (!name) return toast('Project name required', 'error');
    try {
        const r = await api('project.create', {
            name,
            description: document.getElementById('np-desc').value.trim(),
            color: document.getElementById('np-color').value,
        });
        window.location.href = `project.php?id=${r.id}`;
    } catch (err) {
        toast(err.message, 'error');
    }
});

// ---- Delete project ----
document.querySelectorAll('.delete-project').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!confirm(`Delete project "${btn.dataset.name}" and all its tasks & notes?`)) return;
        try {
            await api('project.delete', { id: Number(btn.dataset.id) });
            btn.closest('.group').remove();
            toast('Project deleted');
        } catch (err) {
            toast(err.message, 'error');
        }
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
