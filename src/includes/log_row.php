<?php
/**
 * Renders a single MCP invocation table row. Expects $log in scope.
 */
$statusCls = ($log['status'] ?? 'ok') === 'error'
    ? 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30'
    : 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30';
$ticket = !empty($log['task_id']) ? ticket_key($log['task_id']) : '—';
$run    = !empty($log['run_id']) ? '#' . (int) $log['run_id'] : '—';
$dur    = isset($log['duration_ms']) && $log['duration_ms'] !== null ? ((int) $log['duration_ms']) . ' ms' : '—';
$params = substr((string) ($log['params'] ?? ''), 0, 120);
$result = substr((string) ($log['result'] ?? ''), 0, 120);
$rowTitle = !empty($log['task_id'])
    ? ($ticket . (($log['task_title'] ?? '') !== '' ? ' - ' . $log['task_title'] : ''))
    : '';
?>
<tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40 <?= !empty($log['task_id']) ? 'cursor-pointer' : '' ?>"
    <?php if (!empty($log['task_id'])): ?>data-task-id="<?= (int) $log['task_id'] ?>" data-task-title="<?= e($rowTitle) ?>" title="View session details"<?php endif; ?>>
    <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs text-slate-500"><?= e($log['created_at']) ?></td>
    <td class="px-4 py-2.5 font-mono text-xs text-slate-500"><?= e($run) ?></td>
    <td class="px-4 py-2.5"><code class="text-indigo-500 dark:text-indigo-400"><?= e($log['tool']) ?></code></td>
    <td class="px-4 py-2.5 font-mono text-xs"><?= e($ticket) ?></td>
    <td class="px-4 py-2.5"><span class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full <?= $statusCls ?>"><?= e($log['status']) ?></span></td>
    <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs text-slate-500"><?= e($dur) ?></td>
    <td class="px-4 py-2.5 text-xs text-slate-500 max-w-md">
        <span class="font-mono"><?= e($params) ?></span>
        <?php if ($result !== ''): ?><span class="text-slate-400">→</span> <span class="font-mono"><?= e($result) ?></span><?php endif; ?>
    </td>
</tr>
