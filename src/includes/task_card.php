<?php
/**
 * Kanban task card (redesigned).
 * Expects $task in scope (a full tasks row incl. AI-agent fields).
 */
$pk        = priority_key($task['priority'] ?? 2);
$locked    = task_is_ai_locked($task);
$isAi      = ($task['created_by'] ?? 'human') === 'ai';
$type      = $task['task_type'] ?? 'feature';
$aiStatus  = $task['ai_execution_status'] ?? 'pending';
$criteria  = parse_acceptance_criteria($task['acceptance_criteria'] ?? '');
$comments  = parse_ai_comments($task['ai_comments'] ?? '', 3);
$key       = ticket_key($task['id']);
$ringCls   = $locked ? 'ring-1 ring-amber-500/40 border-amber-500/40' : 'border-slate-200 dark:border-slate-800';
?>
<div class="task-card group relative bg-white dark:bg-slate-900 rounded-xl border <?= $ringCls ?> shadow-sm hover:shadow-md transition
            <?= $locked ? 'task-locked cursor-not-allowed' : 'cursor-grab active:cursor-grabbing' ?>"
     data-id="<?= (int) $task['id'] ?>"
     data-title="<?= e($task['title']) ?>"
     data-description="<?= e($task['description']) ?>"
     data-priority="<?= $pk ?>"
     data-task-type="<?= e($type) ?>"
     data-acceptance="<?= e($task['acceptance_criteria'] ?? '') ?>"
     data-status="<?= e($task['status']) ?>"
     data-locked="<?= $locked ? '1' : '0' ?>"
     data-search="<?= e(strtolower($key . ' ' . $task['title'] . ' ' . $task['description'] . ' ' . $type)) ?>">

    <!-- Priority strip -->
    <span class="absolute left-0 top-0 bottom-0 w-1 rounded-l-xl <?= PRIORITY_COLORS[$pk] ?>"></span>

    <div class="p-3.5 pl-4">
        <!-- Compact view: key + priority/status pills on top, title below -->
        <!-- (wraps up to 2 lines). Shown when the board's Compact switch is on; -->
        <!-- see project.js / project.php. -->
        <div class="compact-row hidden flex-col gap-1">
            <div class="flex items-center flex-wrap gap-1.5">
                <span class="text-xs font-mono font-bold text-slate-500 dark:text-slate-300"><?= e($key) ?></span>
                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full <?= PRIORITY_PILL_CLASSES[$pk] ?>"><?= e(PRIORITY_PILLS[$pk]) ?></span>
                <span class="status-capsule text-[10px] font-semibold px-2 py-0.5 rounded-full <?= STATUS_PILL_CLASSES[$task['status']] ?? STATUS_PILL_CLASSES['pending'] ?>">
                    <?= e(TASK_STATUSES[$task['status']] ?? $task['status']) ?>
                </span>
            </div>
            <p class="task-title font-semibold text-sm leading-snug line-clamp-2 cursor-pointer hover:text-indigo-400"><?= e($task['title']) ?></p>
        </div>
        <div class="card-full-content">
        <!-- Header row: key + title, lock/robot -->
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-[11px] font-mono text-slate-400"><?= e($key) ?></p>
                <p class="task-title font-semibold text-sm leading-snug mt-0.5 cursor-pointer hover:text-indigo-400"><?= e($task['title']) ?></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <?php if ($isAi): ?><span title="Drafted by AI">🤖</span><?php endif; ?>
                <?php if ($locked): ?><span title="Locked - agent working">🔒</span><?php endif; ?>
                <?php if (!$locked): ?>
                    <button class="edit-task opacity-0 group-hover:opacity-100 text-slate-400 hover:text-indigo-500 text-xs">✎</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Badge row -->
        <div class="flex flex-wrap items-center gap-1.5 mt-2">
            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full <?= PRIORITY_PILL_CLASSES[$pk] ?>"><?= e(PRIORITY_PILLS[$pk]) ?></span>
            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full <?= TASK_TYPE_BADGES[$type] ?? TASK_TYPE_BADGES['feature'] ?>"><?= e(ucwords(str_replace('-', ' ', $type))) ?></span>
            <?php if ($isAi): ?>
                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-300 ring-1 ring-purple-500/30">AI DRAFT</span>
            <?php endif; ?>
            <?php if (!empty($task['cost_usd'])): ?>
                <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                      title="<?= (int) $task['run_count'] ?> agent run(s)">$<?= number_format((float) $task['cost_usd'], 3) ?></span>
            <?php endif; ?>
        </div>

        <!-- AI status pill (non-pending states) -->
        <?php if ($aiStatus !== 'pending'): ?>
            <div class="mt-2">
                <span class="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full <?= AI_STATUS_PILL_CLASSES[$aiStatus] ?? AI_STATUS_PILL_CLASSES['pending'] ?>">
                    <?= e($aiStatus) ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if (!empty($task['description'])): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 line-clamp-2"><?= e($task['description']) ?></p>
        <?php endif; ?>

        <!-- Acceptance criteria checklist -->
        <?php if ($criteria): ?>
            <ul class="mt-2.5 space-y-1 criteria-list">
                <?php foreach ($criteria as $i => $c): ?>
                    <li class="flex items-start gap-2 text-xs">
                        <input type="checkbox" class="criteria-box mt-0.5 accent-indigo-500" data-index="<?= $i ?>" <?= $c['checked'] ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                        <span class="<?= $c['checked'] ? 'line-through text-slate-400' : 'text-slate-600 dark:text-slate-300' ?>"><?= e($c['text']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- AI Comments (recent) -->
        <?php if ($comments): ?>
            <div class="mt-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 p-2.5">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1.5 flex items-center gap-1">💬 AI Comments · Recent</p>
                <ul class="space-y-1">
                    <?php foreach ($comments as $c): ?>
                        <li class="flex items-baseline justify-between gap-2 text-[11px]">
                            <span class="text-slate-600 dark:text-slate-300 truncate"><?= e($c['text']) ?></span>
                            <?php if ($c['time']): ?><span class="text-slate-400 font-mono shrink-0"><?= e($c['time']) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="mt-3 flex items-center justify-between text-[11px] text-slate-400">
            <button class="details-task hover:text-indigo-500 flex items-center gap-1">☰ Details</button>
            <?php if ($comments || $criteria): ?>
                <span><?= count($criteria) ? array_sum(array_map(fn($c) => $c['checked'] ? 1 : 0, $criteria)) . '/' . count($criteria) . ' done' : '' ?></span>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>
