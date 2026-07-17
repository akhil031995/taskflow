<?php
/**
 * Single-project view.
 *   - Kanban board: 4 columns, SortableJS drag-and-drop, AJAX status updates
 *   - Tabbed Notes: Quill WYSIWYG with AJAX auto-save
 */
require_once __DIR__ . '/includes/functions.php';

$projectId = (int) ($_GET['id'] ?? 0);
$project   = get_project($projectId);

if (!$project) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/includes/header.php';
    echo '<p class="text-gray-500">Project not found. <a class="text-indigo-600 hover:underline" href="index.php">Back to dashboard</a>.</p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $project['name'];
$tasks     = get_tasks_for_project($projectId);
$notes     = get_notes_for_project($projectId);

// Group tasks by Kanban column.
$byStatus = array_fill_keys(array_keys(TASK_STATUSES), []);
foreach ($tasks as $t) {
    $byStatus[$t['status']][] = $t;
}

require __DIR__ . '/includes/header.php';
?>

<!-- ===================== HEADER ===================== -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">←</a>
        <span class="h-4 w-4 rounded-full" style="background: <?= e($project['color']) ?>"></span>
        <div>
            <h1 class="text-2xl font-bold leading-tight"><?= e($project['name']) ?></h1>
            <?php if (!empty($project['description'])): ?>
                <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($project['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <button id="add-task-btn"
        class="px-3 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">+ Add Task</button>
</div>

<!-- ===================== VIEW TABS (Board / Notes) ===================== -->
<div class="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-6">
    <button data-view="board" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-indigo-600">Board</button>
    <button data-view="notes" class="view-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500">Notes</button>
</div>

<!-- ===================== KANBAN BOARD ===================== -->
<section id="view-board" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
    <?php foreach (TASK_STATUSES as $statusKey => $statusLabel): ?>
        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-col">
            <div class="px-4 py-3 flex items-center justify-between">
                <h3 class="font-semibold text-sm uppercase tracking-wide text-gray-600 dark:text-gray-300"><?= e($statusLabel) ?></h3>
                <span class="text-xs bg-gray-200 dark:bg-gray-700 rounded-full px-2 py-0.5"><?= count($byStatus[$statusKey]) ?></span>
            </div>
            <!-- Drop zone: data-status tells the move handler which column this is -->
            <div class="kanban-col flex-1 min-h-[120px] px-3 pb-3 space-y-2" data-status="<?= e($statusKey) ?>">
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
            <div class="grid grid-cols-2 gap-3">
                <label class="text-sm">Status
                    <select id="tf-status" class="mt-1 w-full px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent dark:bg-gray-800">
                        <?php foreach (TASK_STATUSES as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm">Priority
                    <select id="tf-priority" class="mt-1 w-full px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent dark:bg-gray-800">
                        <option value="urgent">Urgent</option>
                        <option value="high">High</option>
                        <option value="medium" selected>Medium</option>
                        <option value="low">Low</option>
                    </select>
                </label>
            </div>
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

<script>
const PROJECT_ID = <?= $projectId ?>;
const PRIORITY_CLASSES = <?= json_encode(PRIORITY_COLORS) ?>;
</script>
<script src="assets/project.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
