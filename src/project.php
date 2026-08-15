<?php
/**
 * Single-project view.
 *   - Kanban board: 4 columns, SortableJS drag-and-drop, AJAX status updates
 *   - Tabbed Notes: Quill WYSIWYG with AJAX auto-save
 */
require_once __DIR__ . '/includes/functions.php';

$projectId = (int) ($_GET['id'] ?? 0);
$project   = get_project($projectId);

$activeNav       = 'projects';
$activeProjectId = $projectId;

if (!$project) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/header.php';
    echo '<p class="text-slate-400">Project not found. <a class="text-indigo-400 hover:underline" href="index.php">Back to dashboard</a>.</p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle   = $project['name'];
$tasks       = get_tasks_for_project($projectId);
$notes       = get_notes_for_project($projectId);
$projectCost = get_project_run_cost($projectId);

// Group tasks by Kanban column.
$byStatus = array_fill_keys(array_keys(TASK_STATUSES), []);
foreach ($tasks as $t) {
    $byStatus[$t['status']][] = $t;
}

// Completed reads as a history log (most recently finished first), not a
// manually-reorderable column like the others - so it sorts by completion
// time instead of the drag-and-drop `position` used everywhere else.
// ai_completed_at covers AI-finished tickets; updated_at (bumped by any
// UPDATE, including a manual drag-to-Completed) covers human completions.
usort($byStatus['completed'], function (array $a, array $b): int {
    $at = $a['ai_completed_at'] ?: $a['updated_at'];
    $bt = $b['ai_completed_at'] ?: $b['updated_at'];
    return strcmp((string) $bt, (string) $at);
});

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== HEADER ===================== -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex items-center gap-3 min-w-0">
        <span class="h-3.5 w-3.5 rounded-full shrink-0" style="background: <?= e($project['color']) ?>"></span>
        <div class="min-w-0">
            <h1 class="text-xl font-bold leading-tight truncate">Project · <?= e($project['name']) ?></h1>
            <?php if (!empty($project['description'])): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400 truncate"><?= e($project['description']) ?></p>
            <?php endif; ?>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1.5 text-xs">
                <?php if (!empty($project['folder_path'])): ?>
                    <span class="flex items-center gap-1.5 text-slate-500 dark:text-slate-400" title="Agent working directory">
                        📁 <code class="font-mono text-slate-600 dark:text-slate-300"><?= e($project['folder_path']) ?></code>
                    </span>
                <?php endif; ?>
                <?php if (!empty($project['access_url'])): ?>
                    <a href="<?= e($project['access_url']) ?>" target="_blank" rel="noopener"
                       class="flex items-center gap-1.5 text-indigo-500 dark:text-indigo-400 hover:underline">
                        🔗 Open app
                    </a>
                <?php endif; ?>
                <?php if ($projectCost): ?>
                    <span class="flex items-center gap-1.5 text-slate-500 dark:text-slate-400" title="<?= (int) $projectCost['runs'] ?> agent run(s) total">
                        💰 <span class="font-mono text-slate-700 dark:text-slate-200">$<?= number_format((float) $projectCost['cost_usd'], 3) ?></span> agent cost
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <button id="add-task-btn" class="px-3 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">+ Add Task</button>
        <button class="px-3 py-2 rounded-lg text-sm font-medium bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700">⊞ Edit board</button>
    </div>
</div>

<!-- ===================== VIEW TABS (Board / Notes) ===================== -->
<div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 dark:border-slate-800 mb-5">
    <div class="flex gap-1">
        <button data-view="board" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-indigo-500">Board</button>
        <button data-view="notes" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">Notes</button>
        <button data-view="standards" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">Standards</button>
        <button data-view="dod" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">DoD Gates</button>
        <button data-view="primer" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">Primer</button>
    </div>
    <div id="board-view-toggle" class="flex items-center gap-3 pb-2 text-xs text-slate-500 dark:text-slate-400">
        <label class="flex items-center gap-2 cursor-pointer select-none">
            <span>Compact</span>
            <span class="relative inline-flex h-5 w-9 items-center shrink-0">
                <input type="checkbox" id="board-view-compact" aria-label="Compact card density" class="peer sr-only">
                <span class="absolute inset-0 rounded-full bg-slate-300 dark:bg-slate-700 peer-checked:bg-indigo-500 transition-colors"></span>
                <span class="absolute left-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
            </span>
        </label>
    </div>
</div>

<!-- ===================== KANBAN BOARD ===================== -->
<section id="view-board" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
    <?php foreach (TASK_STATUSES as $statusKey => $statusLabel): ?>
        <div class="bg-slate-100/60 dark:bg-slate-900/40 rounded-xl border border-slate-200 dark:border-slate-800 flex flex-col">
            <div class="px-4 py-3 flex items-center justify-between">
                <h3 class="font-semibold text-sm text-slate-600 dark:text-slate-300"><?= e($statusLabel) ?></h3>
                <span class="text-xs bg-slate-200 dark:bg-slate-800 text-slate-500 dark:text-slate-400 rounded-full px-2 py-0.5"><?= count($byStatus[$statusKey]) ?></span>
            </div>
            <!-- Drop zone: data-status tells the move handler which column this is -->
            <div class="kanban-col flex-1 min-h-[140px] px-3 pb-3 space-y-2.5" data-status="<?= e($statusKey) ?>">
                <?php foreach ($byStatus[$statusKey] as $task): ?>
                    <?php require __DIR__ . '/includes/task_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- ===================== NOTES ===================== -->
<section id="view-notes" class="hidden">
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
        <!-- Note tabs -->
        <div class="flex items-center gap-1 border-b border-gray-200 dark:border-gray-700 px-2 overflow-x-auto" id="note-tabs">
            <?php foreach ($notes as $i => $note): ?>
                <button class="note-tab whitespace-nowrap px-3 py-2 text-sm border-b-2 <?= $i === 0 ? 'border-indigo-600 font-medium' : 'border-transparent text-gray-500' ?>"
                        data-id="<?= (int) $note['id'] ?>"><?= e($note['title']) ?></button>
            <?php endforeach; ?>
            <button id="add-note" class="px-3 py-2 text-sm text-indigo-600 hover:underline">+ Note</button>
        </div>

        <!-- Editor toolbar area -->
        <div class="flex items-center justify-between px-4 py-2 text-xs text-gray-400" id="note-meta">
            <span id="note-status">Select or create a note</span>
            <div class="flex gap-2">
                <button id="rename-note" class="hover:text-gray-600 dark:hover:text-gray-200 hidden">Rename</button>
                <button id="delete-note" class="hover:text-red-500 hidden">Delete</button>
            </div>
        </div>

        <!-- Quill editor -->
        <div class="p-2">
            <div id="editor" class="min-h-[300px]"></div>
        </div>
    </div>

    <!-- Note contents are injected as JSON to avoid escaping headaches -->
    <script type="application/json" id="notes-data"><?= json_encode(array_map(fn ($n) => [
        'id'      => (int) $n['id'],
        'title'   => $n['title'],
        'content' => $n['content'],
    ], $notes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
</section>

<!-- ===================== STANDARDS (org baseline + project override) ===================== -->
<section id="view-standards" class="hidden max-w-3xl">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1">🌐 Org Baseline</h2>
        <p class="text-xs text-slate-500 mb-3">Read-only here &mdash; edit under <a class="text-indigo-500 hover:underline" href="settings.php">MCP Settings</a>. Applies to every project.</p>
        <pre id="std-baseline" class="text-xs whitespace-pre-wrap font-mono bg-slate-50 dark:bg-slate-800/60 rounded-lg p-3 text-slate-600 dark:text-slate-300"></pre>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">📌 Project Overrides</h2>
            <button id="std-save" class="px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">Save</button>
        </div>
        <p class="text-xs text-slate-500 mb-3">Rules specific to <?= e($project['name']) ?>. Merged with the org baseline above.</p>
        <textarea id="std-override" rows="8" placeholder="e.g. this project uses PSR-12, run phpunit before marking complete…"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"></textarea>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
        <h2 class="font-semibold mb-1">⚙️ Effective Standards</h2>
        <p class="text-xs text-slate-500 mb-3">Baseline + override merged, as resolved when a ticket for this project is next claimed and written into its <code>CLAUDE.md</code>.</p>
        <pre id="std-effective" class="text-xs whitespace-pre-wrap font-mono bg-slate-50 dark:bg-slate-800/60 rounded-lg p-3 text-slate-600 dark:text-slate-300"></pre>
    </div>
</section>

<!-- ===================== DoD GATES (per-project lint/test/build + criteria) ===================== -->
<section id="view-dod" class="hidden max-w-3xl">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">✅ Definition-of-Done Gates</h2>
            <button id="dod-save" class="px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">Save</button>
        </div>
        <p class="text-xs text-slate-500 mb-4">
            Commands run (in this project's working directory) by the MCP server before a ticket is allowed to
            reach <strong>Completed</strong>. Leave a field blank to skip that gate. If any configured gate fails,
            the ticket is blocked instead, with the captured output attached as a comment.
        </p>
        <div class="space-y-3">
            <label class="text-sm block">Lint command
                <input id="dod-lint" type="text" placeholder="e.g. npm run lint / phpcs --standard=PSR12 src"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm block">Test command
                <input id="dod-test" type="text" placeholder="e.g. npm test / phpunit"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm block">Build command
                <input id="dod-build" type="text" placeholder="e.g. npm run build / docker build ."
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
        </div>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
        <h2 class="font-semibold mb-1">📋 Acceptance-Criteria Template</h2>
        <p class="text-xs text-slate-500 mb-3">
            Applied to new tickets in this project when the ticket is created without its own criteria. Leave blank
            to use the org-wide default shown as the placeholder below.
        </p>
        <textarea id="dod-criteria" rows="5" placeholder=""
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"></textarea>
    </div>
</section>

<!-- ===================== PRIMER (cached project structure summary) ===================== -->
<section id="view-primer" class="hidden max-w-3xl">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">🧭 Project Primer</h2>
            <span id="primer-updated" class="text-xs text-slate-400"></span>
        </div>
        <p class="text-xs text-slate-500 mb-3">
            Auto-generated summary of this project's structure, detected stack, and key files. Regenerated only
            when the file tree changes and written into <code>CLAUDE.md</code> the next time a ticket for this
            project is claimed, so a headless agent run doesn't have to re-derive project shape from scratch.
            Read-only &mdash; edit the underlying code to change it, not this page.
        </p>
        <pre id="primer-content" class="text-xs whitespace-pre-wrap font-mono bg-slate-50 dark:bg-slate-800/60 rounded-lg p-3 text-slate-600 dark:text-slate-300">(no primer generated yet — runs the next time a ticket is claimed for this project)</pre>
    </div>
</section>

<!-- ===================== TASK MODAL (create / edit) ===================== -->
<div id="task-modal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl w-full max-w-md p-6 shadow-xl">
        <h3 id="task-modal-title" class="text-lg font-semibold mb-4">Add Task</h3>
        <input type="hidden" id="tf-id">
        <div class="space-y-3">
            <input id="tf-title" type="text" placeholder="Task title"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent">
            <textarea id="tf-desc" rows="3" placeholder="Description (optional)"
                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent"></textarea>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="text-sm">Status
                    <select id="tf-status" class="mt-1 w-full px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent dark:bg-gray-800">
                        <?php foreach (TASK_STATUSES as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm">Priority
                    <select id="tf-priority" class="mt-1 w-full px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent dark:bg-gray-800">
                        <?php foreach (PRIORITY_LABELS as $pk => $plabel): ?>
                            <option value="<?= $pk ?>" <?= $pk === 2 ? 'selected' : '' ?>><?= $pk ?>-<?= e($plabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm">Type
                    <select id="tf-type" class="mt-1 w-full px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent dark:bg-gray-800">
                        <?php foreach (TASK_TYPES as $tt): ?>
                            <option value="<?= e($tt) ?>"><?= e($tt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label class="text-sm block">Acceptance criteria
                <textarea id="tf-acceptance" rows="4"
                    placeholder="Leave blank to auto-fill the default checklist template"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent font-mono text-xs"></textarea>
            </label>
        </div>
        <div class="flex justify-between mt-5">
            <button id="tf-delete" class="px-4 py-2 rounded-lg text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 hidden">Delete</button>
            <div class="flex gap-2 ml-auto">
                <button id="tf-cancel" class="px-4 py-2 rounded-lg text-sm hover:bg-gray-100 dark:hover:bg-gray-700">Cancel</button>
                <button id="tf-save" class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white hover:bg-indigo-700">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== TASK DETAIL MODAL ===================== -->
<div id="detail-modal" class="hidden fixed inset-0 z-[65] bg-black/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl w-full max-w-4xl max-h-[88vh] shadow-2xl border border-slate-200 dark:border-slate-800 flex flex-col">
        <!-- Header -->
        <div class="flex items-start justify-between gap-3 px-6 py-4 border-b border-slate-200 dark:border-slate-800">
            <div class="min-w-0">
                <p id="dm-key" class="text-xs font-mono text-slate-400">TF-000</p>
                <h2 id="dm-title" class="text-lg font-bold leading-snug"></h2>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button id="dm-edit" class="px-3 py-1.5 rounded-lg text-sm bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700">✎ Edit</button>
                <button id="dm-close" class="px-2.5 py-1.5 rounded-lg text-sm hover:bg-slate-100 dark:hover:bg-slate-800">✕</button>
            </div>
        </div>
        <!-- Body: comments (left) | details + status + requirements (right) -->
        <div class="flex-1 overflow-y-auto grid md:grid-cols-2 gap-0 md:divide-x divide-slate-200 dark:divide-slate-800">
            <div class="p-6 overflow-y-auto">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">💬 Comments &amp; Activity</h3>
                <ol id="dm-comments" class="space-y-3"></ol>
            </div>
            <div class="p-6 overflow-y-auto space-y-5">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Status</h3>
                    <div id="dm-status" class="flex flex-wrap gap-2"></div>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Timeline (IST)</h3>
                    <dl id="dm-timeline" class="text-sm grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5"></dl>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Requirements</h3>
                    <ul id="dm-criteria" class="space-y-1.5 text-sm"></ul>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Description</h3>
                    <p id="dm-desc" class="text-sm text-slate-600 dark:text-slate-300 whitespace-pre-wrap"></p>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Workspace</h3>
                    <div id="dm-workspace" class="text-sm space-y-1"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PROJECT_ID = <?= $projectId ?>;
const PRIORITY_CLASSES = <?= json_encode(PRIORITY_COLORS) ?>;
const ACCEPTANCE_TEMPLATE = <?= json_encode(effective_acceptance_criteria($projectId)) ?>;
</script>
<script src="assets/project.js?v=<?= @filemtime(__DIR__ . '/assets/project.js') ?>"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
