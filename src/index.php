<?php
/**
 * Dashboard / home page.
 *   - Stat widgets (projects, tasks, completion %, MCP invocations)
 *   - Unified "In Progress" feed across all projects (filtered by topbar search)
 *   - Project Hub grid (+ create/delete project)
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
$stats      = get_stats();
$projects   = get_projects();
$inProgress = get_in_progress_tasks();
$mcpStats   = get_mcp_log_stats();

require __DIR__ . '/includes/header.php';

/** Small stat-card renderer. */
function stat_card(string $label, string $value, string $accent = ''): void { ?>
    <div class="bg-white dark:bg-slate-900 rounded-xl p-5 border border-slate-200 dark:border-slate-800">
        <p class="text-sm text-slate-500 dark:text-slate-400"><?= e($label) ?></p>
        <p class="text-3xl font-bold mt-1 <?= $accent ?>"><?= $value /* pre-escaped */ ?></p>
    </div>
<?php }
?>

<h1 class="text-xl font-bold mb-5">Dashboard</h1>

<!-- ===================== STAT WIDGETS ===================== -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php
    stat_card('Total Projects', (string) (int) $stats['projects']);
    stat_card('Total Tasks', (string) (int) $stats['tasks']);
    ?>
    <div class="bg-white dark:bg-slate-900 rounded-xl p-5 border border-slate-200 dark:border-slate-800">
        <p class="text-sm text-slate-500 dark:text-slate-400">Completion</p>
        <div class="flex items-center gap-3 mt-1">
            <p class="text-3xl font-bold"><?= (int) $stats['completion'] ?>%</p>
            <div class="flex-1 h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                <div class="h-full bg-emerald-500" style="width: <?= (int) $stats['completion'] ?>%"></div>
            </div>
        </div>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl p-5 border border-slate-200 dark:border-slate-800">
        <p class="text-sm text-slate-500 dark:text-slate-400">MCP Invocations</p>
        <p class="text-3xl font-bold mt-1"><?= (int) $mcpStats['total'] ?>
            <?php if ($mcpStats['errors'] > 0): ?>
                <span class="text-sm font-medium text-rose-400"><?= (int) $mcpStats['errors'] ?> err</span>
            <?php endif; ?>
        </p>
        <a href="logs.php" class="text-xs text-indigo-400 hover:underline">View agent logs →</a>
    </div>
</section>

<!-- ===================== TOOL USAGE + TOKEN USAGE ===================== -->
<?php
$toolUsage  = get_tool_usage_stats(8);
$tokenUsage = get_project_token_usage();
?>
<section class="mb-10 grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white dark:bg-slate-900 rounded-xl p-5 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-slate-600 dark:text-slate-300">Tool Usage <span class="font-normal text-slate-400">· all-time, top 8</span></h3>
            <a href="logs.php#by-tool" class="text-xs text-indigo-400 hover:underline">Full breakdown →</a>
        </div>
        <?php if (empty($toolUsage)): ?>
            <p class="text-sm text-slate-500 dark:text-slate-400">No MCP tool calls recorded yet.</p>
        <?php else: $maxCount = max(array_column($toolUsage, 'count')); ?>
            <div class="space-y-2.5">
                <?php foreach ($toolUsage as $row): $pct = $maxCount > 0 ? round($row['count'] / $maxCount * 100) : 0; ?>
                    <div class="flex items-center gap-3">
                        <span class="w-32 sm:w-40 shrink-0 truncate text-xs font-mono text-slate-500 dark:text-slate-400" title="<?= e($row['tool']) ?> (<?= e($row['server']) ?>)"><?= e($row['tool']) ?></span>
                        <div class="flex-1 h-4 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div class="h-full rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-xs font-mono text-slate-600 dark:text-slate-300"><?= (int) $row['count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl p-5 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-slate-600 dark:text-slate-300">Token Usage <span class="font-normal text-slate-400">by project, all-time</span></h3>
            <?php $totalTokens = array_sum(array_column($tokenUsage, 'total_tokens')); $totalCost = array_sum(array_column($tokenUsage, 'cost_usd')); ?>
            <?php if ($totalTokens > 0): ?>
                <span class="text-xs font-mono text-slate-400"><?= e(format_compact_number($totalTokens)) ?> tok · $<?= number_format($totalCost, 2) ?></span>
            <?php endif; ?>
        </div>
        <?php if (empty($tokenUsage)): ?>
            <p class="text-sm text-slate-500 dark:text-slate-400">No agent runs recorded yet.</p>
        <?php else: $maxTokens = max(array_column($tokenUsage, 'total_tokens')); ?>
            <div class="space-y-2.5">
                <?php foreach ($tokenUsage as $row): $pct = $maxTokens > 0 ? round($row['total_tokens'] / $maxTokens * 100) : 0;
                    $tip = sprintf(
                        '%s — input %s · output %s · cache create %s · cache read %s · %d run(s) · $%s',
                        $row['project_name'],
                        number_format($row['input_tokens']),
                        number_format($row['output_tokens']),
                        number_format($row['cache_creation_tokens']),
                        number_format($row['cache_read_tokens']),
                        $row['runs'],
                        number_format($row['cost_usd'], 3)
                    );
                ?>
                    <a href="project.php?id=<?= (int) $row['project_id'] ?>" class="flex items-center gap-3 group" title="<?= e($tip) ?>">
                        <span class="w-24 sm:w-32 shrink-0 flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 group-hover:text-indigo-500">
                            <span class="h-2 w-2 rounded-full shrink-0" style="background: <?= e($row['project_color']) ?>"></span>
                            <span class="truncate"><?= e($row['project_name']) ?></span>
                        </span>
                        <div class="flex-1 h-4 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div class="h-full rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div>
                        </div>
                        <span class="w-16 shrink-0 text-right text-xs font-mono text-slate-600 dark:text-slate-300"><?= e(format_compact_number($row['total_tokens'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ===================== IN PROGRESS FEED ===================== -->
<section class="mb-10">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="text-lg font-semibold">In Progress</h2>
        <span class="text-xs text-slate-500">across all projects</span>
    </div>

    <div id="inprogress-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php if (empty($inProgress)): ?>
            <p class="text-slate-500 dark:text-slate-400 col-span-full">Nothing in progress right now. 🎉</p>
        <?php endif; ?>
        <?php foreach ($inProgress as $task): $pk = priority_key($task['priority'] ?? 2); ?>
            <a href="project.php?id=<?= (int) $task['project_id'] ?>"
               class="block bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 hover:shadow-md transition overflow-hidden"
               data-search="<?= e(strtolower(ticket_key($task['id']) . ' ' . $task['title'] . ' ' . $task['description'] . ' ' . $task['project_name'])) ?>">
                <div class="flex">
                    <span class="w-1 <?= PRIORITY_COLORS[$pk] ?>"></span>
                    <div class="p-4 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[11px] font-mono text-slate-400"><?= e(ticket_key($task['id'])) ?></span>
                            <span class="text-xs font-medium truncate" style="color: <?= e($task['project_color']) ?>"><?= e($task['project_name']) ?></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full <?= PRIORITY_PILL_CLASSES[$pk] ?>"><?= e(PRIORITY_PILLS[$pk]) ?></span>
                            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full <?= TASK_TYPE_BADGES[$task['task_type'] ?? 'feature'] ?? TASK_TYPE_BADGES['feature'] ?>"><?= e(ucwords(str_replace('-', ' ', $task['task_type'] ?? 'feature'))) ?></span>
                            <?php if (($task['created_by'] ?? 'human') === 'ai'): ?>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-300 ring-1 ring-purple-500/30">AI DRAFT</span>
                            <?php endif; ?>
                        </div>
                        <p class="font-semibold text-sm"><?= e($task['title']) ?></p>
                        <?php if (!empty($task['description'])): ?>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-2"><?= e($task['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <p data-empty-when-filtered="#inprogress-list" class="hidden text-slate-500 dark:text-slate-400 mt-2">No matching tasks.</p>
</section>

<!-- ===================== PROJECT HUB ===================== -->
<section>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Project Hub</h2>
        <button id="new-project-btn" class="px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">+ New Project</button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php $projectCosts = get_all_project_run_costs(); ?>
        <?php foreach ($projects as $p):
            $ptasks = get_tasks_for_project((int) $p['id']);
            $total  = count($ptasks);
            $done   = count(array_filter($ptasks, fn ($t) => $t['status'] === 'completed'));
            $pcost  = $projectCosts[(int) $p['id']] ?? null;
        ?>
            <div class="group relative bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 hover:shadow-md transition"
                 data-search="<?= e(strtolower($p['name'] . ' ' . $p['description'])) ?>">
                <a href="project.php?id=<?= (int) $p['id'] ?>" class="block p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="h-3 w-3 rounded-full" style="background: <?= e($p['color']) ?>"></span>
                        <h3 class="font-semibold truncate"><?= e($p['name']) ?></h3>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 line-clamp-2 min-h-[2.5rem]"><?= e($p['description']) ?></p>
                    <div class="mt-4 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span><?= $total ?> tasks</span>
                        <span><?= $done ?>/<?= $total ?> done</span>
                    </div>
                    <?php if ($pcost): ?>
                        <div class="mt-1 text-[11px] font-mono text-slate-400" title="<?= (int) $pcost['runs'] ?> agent run(s)">$<?= number_format((float) $pcost['cost_usd'], 3) ?> agent cost</div>
                    <?php endif; ?>
                </a>
                <button class="delete-project absolute top-3 right-3 opacity-0 group-hover:opacity-100 text-slate-400 hover:text-red-500"
                        data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" title="Delete project">🗑</button>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===================== NEW PROJECT MODAL ===================== -->
<div id="project-modal" class="hidden fixed inset-0 z-[60] bg-black/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-xl w-full max-w-md p-6 shadow-xl border border-slate-200 dark:border-slate-800">
        <h3 class="text-lg font-semibold mb-4">New Project</h3>
        <div class="space-y-3">
            <input id="np-name" type="text" placeholder="Project name"
                class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent">
            <textarea id="np-desc" rows="2" placeholder="Description (optional)"
                class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent"></textarea>
            <label class="text-sm block">
                <span class="text-slate-500 dark:text-slate-400">Project folder location</span>
                <div class="mt-1 flex gap-2">
                    <input id="np-folder" type="text" placeholder="/home/akhil/development/myapp"
                        class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                    <button type="button" id="np-browse" class="px-3 py-2 rounded-lg text-sm bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 whitespace-nowrap">📁 Browse</button>
                </div>
                <span class="text-[11px] text-slate-500">Pick a folder, or type the path. The agent stays scoped to it.</span>
            </label>
            <label class="text-sm block">
                <span class="text-slate-500 dark:text-slate-400">Access URL <span class="text-slate-400">(optional)</span></span>
                <input id="np-url" type="text" placeholder="https://myapp.example.com"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent text-xs">
            </label>
            <label class="flex items-center gap-2 text-sm">
                Accent color <input id="np-color" type="color" value="#6366f1" class="h-8 w-12 rounded bg-transparent">
            </label>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button id="np-cancel" class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100 dark:hover:bg-slate-800">Cancel</button>
            <button id="np-save" class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white hover:bg-indigo-700">Create</button>
        </div>
    </div>
</div>

<!-- ===================== FOLDER PICKER MODAL ===================== -->
<div id="folder-modal" class="hidden fixed inset-0 z-[70] bg-black/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-xl w-full max-w-lg shadow-xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[80vh]">
        <div class="p-4 border-b border-slate-200 dark:border-slate-800">
            <h3 class="text-lg font-semibold">Select project folder</h3>
            <p id="fp-path" class="text-xs font-mono text-slate-500 mt-1 truncate">/</p>
        </div>
        <div class="p-2 flex items-center gap-2 border-b border-slate-200 dark:border-slate-800">
            <button id="fp-up" class="px-2.5 py-1.5 rounded-lg text-sm bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700">⬆ Up</button>
            <span id="fp-hint" class="text-xs text-slate-500"></span>
        </div>
        <ul id="fp-list" class="flex-1 overflow-y-auto p-2 text-sm space-y-0.5"></ul>
        <div class="p-3 border-t border-slate-200 dark:border-slate-800 flex justify-end gap-2">
            <button id="fp-cancel" class="px-4 py-2 rounded-lg text-sm hover:bg-slate-100 dark:hover:bg-slate-800">Cancel</button>
            <button id="fp-select" class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white hover:bg-indigo-700">Use this folder</button>
        </div>
    </div>
</div>

<script>
// ---- Folder picker (server-side directory browser) ----
const fpModal = document.getElementById('folder-modal');
let fpCurrentRel = '';   // path relative to the mounted root
let fpHostPath = '';     // real host path of the current folder

async function fpLoad(rel) {
    try {
        const r = await api('fs.list', { path: rel });
        fpCurrentRel = r.rel;
        fpHostPath = r.host_path;
        document.getElementById('fp-path').textContent = r.host_path;
        document.getElementById('fp-up').disabled = r.parent === null;
        document.getElementById('fp-up').classList.toggle('opacity-40', r.parent === null);
        document.getElementById('fp-hint').textContent = r.dirs.length ? `${r.dirs.length} folder(s)` : 'No sub-folders';
        const list = document.getElementById('fp-list');
        list.innerHTML = '';
        r.dirs.forEach((d) => {
            const li = document.createElement('li');
            li.className = 'flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer';
            li.innerHTML = '<span>📁</span>';
            const name = document.createElement('span');
            name.textContent = d; // safe text
            li.appendChild(name);
            li.addEventListener('click', () => fpLoad(fpCurrentRel ? `${fpCurrentRel}/${d}` : d));
            list.appendChild(li);
        });
        // store parent for the Up button
        document.getElementById('fp-up').dataset.parent = r.parent ?? '';
        document.getElementById('fp-up').dataset.hasParent = r.parent === null ? '0' : '1';
    } catch (err) {
        toast(err.message, 'error');
    }
}

document.getElementById('np-browse').addEventListener('click', () => {
    fpModal.classList.remove('hidden');
    // Start from the current text value's not-easily-mappable, so start at root.
    fpLoad('');
});
document.getElementById('fp-cancel').addEventListener('click', () => fpModal.classList.add('hidden'));
fpModal.addEventListener('click', (e) => { if (e.target === fpModal) fpModal.classList.add('hidden'); });
document.getElementById('fp-up').addEventListener('click', () => {
    const up = document.getElementById('fp-up');
    if (up.dataset.hasParent === '1') fpLoad(up.dataset.parent);
});
document.getElementById('fp-select').addEventListener('click', () => {
    document.getElementById('np-folder').value = fpHostPath;
    fpModal.classList.add('hidden');
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
            folder_path: document.getElementById('np-folder').value.trim(),
            access_url: document.getElementById('np-url').value.trim(),
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
