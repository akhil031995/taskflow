<?php
/**
 * AI Agent Logs - MCP tool invocation log with timestamps, plus a tabbed
 * breakdown report (By Tool / By Server / By Ticket / Timeline).
 * The Invocations tab renders server-side and auto-refreshes via
 * api.php?action=mcp.logs (JSON) without a full reload. The breakdown tabs
 * render an initial server-side snapshot and re-fetch on every tab switch.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle   = 'AI Agent Logs';
$activeNav   = 'logs';
$selectedRun = isset($_GET['run_id']) ? trim((string) $_GET['run_id']) : '';
$runs        = get_runs(50);
$logs        = get_mcp_logs(100, '', $selectedRun);
$logStats    = get_mcp_log_stats();

// Breakdown report data (initial server-side snapshot for each tab).
$toolUsage   = get_tool_usage_stats();      // full list, no limit
$ticketUsage = get_ticket_usage_stats(50);
$timeline    = get_tool_usage_timeline(14);

// "By Server" is the same tool-usage rows, grouped in PHP - no separate query.
$byServer = [];
foreach ($toolUsage as $row) {
    $s = $row['server'];
    if (!isset($byServer[$s])) {
        $byServer[$s] = ['server' => $s, 'count' => 0, 'errors' => 0, 'tools' => 0];
    }
    $byServer[$s]['count']  += $row['count'];
    $byServer[$s]['errors'] += $row['errors'];
    $byServer[$s]['tools']  += 1;
}
usort($byServer, fn ($a, $b) => $b['count'] <=> $a['count']);

/** Short label for a run option: outcome/exit code + ticket + start time. */
function run_option_label(array $r): string
{
    $status = $r['finished_at'] === null
        ? 'running'
        : ($r['outcome'] ?? ($r['exit_code'] === '0' || $r['exit_code'] === 0 ? 'success' : 'error'));
    $ticket = $r['task_id'] ? ticket_key((int) $r['task_id']) : 'no ticket';
    return "#{$r['id']} · {$status} · {$ticket} · {$r['started_at']}";
}

/** Ranked horizontal bar row: label, count, bar filled to $pct of the row's max. */
function usage_bar_row(string $label, int $count, int $pct, string $title = ''): void { ?>
    <div class="flex items-center gap-3">
        <span class="w-48 shrink-0 truncate text-xs font-mono text-slate-500 dark:text-slate-400" title="<?= e($title ?: $label) ?>"><?= e($label) ?></span>
        <div class="flex-1 h-4 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
            <div class="h-full rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div>
        </div>
        <span class="w-10 shrink-0 text-right text-xs font-mono text-slate-600 dark:text-slate-300"><?= $count ?></span>
    </div>
<?php }

require __DIR__ . '/includes/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-xl font-bold flex items-center gap-2">
            AI Agent Logs
            <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_8px] shadow-emerald-400/70"></span>
        </h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">Every MCP tool invocation, most recent first.</p>
    </div>
    <div class="text-right">
        <p class="text-2xl font-bold" id="log-total"><?= (int) $logStats['total'] ?></p>
        <p class="text-xs text-slate-500">invocations</p>
    </div>
</div>

<!-- ===================== REPORT TABS ===================== -->
<div class="flex gap-1 border-b border-slate-200 dark:border-slate-800 mb-5">
    <button data-tab="invocations" class="log-tab px-4 py-2 text-sm font-medium border-b-2 border-indigo-500">Invocations</button>
    <button data-tab="by-tool" class="log-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">By Tool</button>
    <button data-tab="by-server" class="log-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">By Server</button>
    <button data-tab="by-ticket" class="log-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">By Ticket</button>
    <button data-tab="timeline" class="log-tab px-4 py-2 text-sm font-medium border-b-2 border-transparent text-slate-500">Timeline</button>
</div>

<!-- ===================== TAB: INVOCATIONS ===================== -->
<section id="tab-invocations">
    <div class="flex items-center justify-end gap-4 mb-3">
        <label class="flex items-center gap-2 text-xs text-slate-500">
            Run
            <select id="run-filter" class="text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-1">
                <option value="" <?= $selectedRun === '' ? 'selected' : '' ?>>All runs</option>
                <?php foreach ($runs as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $selectedRun === (string) $r['id'] ? 'selected' : '' ?>><?= e(run_option_label($r)) ?></option>
                <?php endforeach; ?>
                <option value="unassigned" <?= $selectedRun === 'unassigned' ? 'selected' : '' ?>>Unassigned (before run tracking)</option>
            </select>
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-500">
            <input id="auto-refresh" type="checkbox" checked class="accent-indigo-500"> Auto-refresh
        </label>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">Time (IST)</th>
                        <th class="px-4 py-2.5 font-medium">Run</th>
                        <th class="px-4 py-2.5 font-medium">Tool</th>
                        <th class="px-4 py-2.5 font-medium">Ticket</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">Duration</th>
                        <th class="px-4 py-2.5 font-medium">Params → Result</th>
                    </tr>
                </thead>
                <tbody id="log-rows" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No MCP invocations recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <?php require __DIR__ . '/includes/log_row.php'; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ===================== TAB: BY TOOL ===================== -->
<section id="tab-by-tool" class="hidden">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h3 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-4">Calls per tool <span class="font-normal text-slate-400">· all-time</span></h3>
        <div id="by-tool-chart" class="space-y-2.5">
            <?php if (empty($toolUsage)): ?>
                <p class="text-sm text-slate-500">No MCP tool calls recorded yet.</p>
            <?php else: $max = max(array_column($toolUsage, 'count')); foreach ($toolUsage as $row):
                usage_bar_row($row['tool'], $row['count'], $max > 0 ? (int) round($row['count'] / $max * 100) : 0, "{$row['tool']} ({$row['server']})");
            endforeach; endif; ?>
        </div>
    </div>
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-medium">Tool</th>
                        <th class="px-4 py-2.5 font-medium">Server</th>
                        <th class="px-4 py-2.5 font-medium text-right">Calls</th>
                        <th class="px-4 py-2.5 font-medium text-right">Errors</th>
                        <th class="px-4 py-2.5 font-medium text-right">Error rate</th>
                        <th class="px-4 py-2.5 font-medium text-right whitespace-nowrap">Avg duration</th>
                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">Last used (IST)</th>
                    </tr>
                </thead>
                <tbody id="by-tool-rows" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($toolUsage as $row): $rate = $row['count'] > 0 ? round($row['errors'] / $row['count'] * 100, 1) : 0; ?>
                        <tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td class="px-4 py-2.5"><code class="text-indigo-500 dark:text-indigo-400"><?= e($row['tool']) ?></code></td>
                            <td class="px-4 py-2.5 text-xs text-slate-500"><?= e($row['server']) ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs"><?= (int) $row['count'] ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs <?= $row['errors'] > 0 ? 'text-rose-400' : 'text-slate-500' ?>"><?= (int) $row['errors'] ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs <?= $rate > 0 ? 'text-rose-400' : 'text-slate-500' ?>"><?= $rate ?>%</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500"><?= $row['avg_ms'] !== null ? $row['avg_ms'] . ' ms' : '—' ?></td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-500"><?= e($row['last_used'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($toolUsage)): ?>
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No MCP tool calls recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ===================== TAB: BY SERVER ===================== -->
<section id="tab-by-server" class="hidden">
    <div id="by-server-cards" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php if (empty($byServer)): ?>
            <p class="text-sm text-slate-500 col-span-full">No MCP tool calls recorded yet.</p>
        <?php else: $maxServer = max(array_column($byServer, 'count')); foreach ($byServer as $s): $pct = $maxServer > 0 ? round($s['count'] / $maxServer * 100) : 0; ?>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
                <p class="text-xs font-mono text-slate-400"><?= e($s['server']) ?></p>
                <p class="text-3xl font-bold mt-1"><?= (int) $s['count'] ?> <span class="text-sm font-normal text-slate-400">calls</span></p>
                <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden mt-3">
                    <div class="h-full rounded-full bg-indigo-500" style="width: <?= $pct ?>%"></div>
                </div>
                <div class="flex items-center justify-between mt-2 text-xs text-slate-500">
                    <span><?= (int) $s['tools'] ?> tool(s)</span>
                    <span class="<?= $s['errors'] > 0 ? 'text-rose-400' : '' ?>"><?= (int) $s['errors'] ?> error(s)</span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</section>

<!-- ===================== TAB: BY TICKET ===================== -->
<section id="tab-by-ticket" class="hidden">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400">
                    <tr class="text-left">
                        <th class="px-4 py-2.5 font-medium">Ticket</th>
                        <th class="px-4 py-2.5 font-medium text-right">Calls</th>
                        <th class="px-4 py-2.5 font-medium text-right">Errors</th>
                        <th class="px-4 py-2.5 font-medium text-right whitespace-nowrap">Total duration</th>
                    </tr>
                </thead>
                <tbody id="by-ticket-rows" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($ticketUsage as $row): ?>
                        <tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40 cursor-pointer" data-task-id="<?= (int) $row['task_id'] ?>" data-task-title="<?= e(ticket_key($row['task_id']) . ($row['task_title'] ? ' - ' . $row['task_title'] : '')) ?>" title="View session details">
                            <td class="px-4 py-2.5"><span class="font-mono text-xs text-slate-400"><?= e(ticket_key($row['task_id'])) ?></span> <?= e($row['task_title'] ?: '(untitled)') ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs"><?= (int) $row['calls'] ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs <?= $row['errors'] > 0 ? 'text-rose-400' : 'text-slate-500' ?>"><?= (int) $row['errors'] ?></td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500"><?= number_format($row['total_ms'] / 1000, 1) ?> s</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ticketUsage)): ?>
                        <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No ticket-linked calls recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ===================== TAB: TIMELINE ===================== -->
<section id="tab-timeline" class="hidden">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
        <h3 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-4">Calls per day <span class="font-normal text-slate-400">· last 14 days</span></h3>
        <div id="timeline-chart">
            <?php $maxDay = max(array_column($timeline, 'count')) ?: 1; ?>
            <div class="flex items-end gap-1.5 h-28 mb-1">
                <?php foreach ($timeline as $d): $h = $d['count'] > 0 ? max((int) round($d['count'] / $maxDay * 100), 4) : 0; ?>
                    <div class="flex-1 h-full flex flex-col justify-end items-center" title="<?= e($d['day']) ?>: <?= (int) $d['count'] ?> call(s)">
                        <div class="w-full rounded-t bg-indigo-500" style="height: <?= $h ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex gap-1.5">
                <?php foreach ($timeline as $d): ?>
                    <div class="flex-1 text-center text-[9px] text-slate-400"><?= e(date('d M', strtotime($d['day']))) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<script>
// -------- Tab switching (hash-linkable, e.g. logs.php#by-tool) --------
const tabButtons = document.querySelectorAll('.log-tab');
const tabPanels = { invocations: '#tab-invocations', 'by-tool': '#tab-by-tool', 'by-server': '#tab-by-server', 'by-ticket': '#tab-by-ticket', timeline: '#tab-timeline' };
const loaders = { 'by-tool': loadByTool, 'by-server': loadByTool, 'by-ticket': loadByTicket, timeline: loadTimeline };

function activateTab(name) {
    if (!tabPanels[name]) name = 'invocations';
    tabButtons.forEach((b) => {
        const on = b.dataset.tab === name;
        b.classList.toggle('border-indigo-500', on);
        b.classList.toggle('border-transparent', !on);
        b.classList.toggle('text-slate-500', !on);
    });
    Object.values(tabPanels).forEach((sel) => document.querySelector(sel).classList.add('hidden'));
    document.querySelector(tabPanels[name]).classList.remove('hidden');
    if (loaders[name]) loaders[name]();
}
tabButtons.forEach((b) => b.addEventListener('click', () => {
    activateTab(b.dataset.tab);
    history.replaceState(null, '', '#' + b.dataset.tab);
}));
activateTab((location.hash || '#invocations').slice(1));

// -------- Live log refresh (Invocations tab) --------
const rowsHost = document.getElementById('log-rows');
const totalEl = document.getElementById('log-total');
const autoRefresh = document.getElementById('auto-refresh');
const runFilter = document.getElementById('run-filter');

const statusPill = (s) => s === 'error'
    ? 'bg-rose-500/15 text-rose-400 ring-1 ring-rose-500/30'
    : 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30';

function esc(v) {
    return String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function renderRow(l) {
    const ticket = l.task_id ? `TF-${String(l.task_id).padStart(3, '0')}` : '—';
    const run = l.run_id ? `#${l.run_id}` : '—';
    const dur = l.duration_ms != null ? `${l.duration_ms} ms` : '—';
    const params = esc((l.params || '').slice(0, 120));
    const result = esc((l.result || '').slice(0, 120));
    const rowTitle = l.task_id ? esc(ticket + (l.task_title ? ' - ' + l.task_title : '')) : '';
    const clickAttrs = l.task_id ? ` data-task-id="${l.task_id}" data-task-title="${rowTitle}" title="View session details"` : '';
    const cursor = l.task_id ? ' cursor-pointer' : '';
    return `<tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40${cursor}"${clickAttrs}>
        <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs text-slate-500">${esc(l.created_at)}</td>
        <td class="px-4 py-2.5 font-mono text-xs text-slate-500">${esc(run)}</td>
        <td class="px-4 py-2.5"><code class="text-indigo-500 dark:text-indigo-400">${esc(l.tool)}</code></td>
        <td class="px-4 py-2.5 font-mono text-xs">${esc(ticket)}</td>
        <td class="px-4 py-2.5"><span class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full ${statusPill(l.status)}">${esc(l.status)}</span></td>
        <td class="px-4 py-2.5 whitespace-nowrap font-mono text-xs text-slate-500">${esc(dur)}</td>
        <td class="px-4 py-2.5 text-xs text-slate-500 max-w-md"><span class="font-mono">${params}</span>${result ? ` <span class="text-slate-400">→</span> <span class="font-mono">${result}</span>` : ''}</td>
    </tr>`;
}

async function refresh() {
    try {
        const r = await api('mcp.logs', { limit: 100, run_id: runFilter.value });
        rowsHost.innerHTML = r.logs.length
            ? r.logs.map(renderRow).join('')
            : `<tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No MCP invocations for this run.</td></tr>`;
        totalEl.textContent = r.total;
    } catch { /* keep last good view */ }
}

let timer = setInterval(() => { if (autoRefresh.checked) refresh(); }, 5000);
runFilter.addEventListener('change', refresh);

// Click a row with a ticket -> open the session drawer (replays recorded logs).
document.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-task-id]');
    if (!tr) return;
    const id = Number(tr.dataset.taskId);
    if (id && window.selectSession) {
        window.selectSession(id, tr.dataset.taskTitle || `TF-${String(id).padStart(3, '0')}`, 'SESSION', 'bg-indigo-400');
    }
});

// -------- Breakdown tabs: re-fetch fresh data every time the tab is opened --------
function barRowHtml(label, count, pct, title) {
    return `<div class="flex items-center gap-3">
        <span class="w-48 shrink-0 truncate text-xs font-mono text-slate-500 dark:text-slate-400" title="${esc(title || label)}">${esc(label)}</span>
        <div class="flex-1 h-4 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
            <div class="h-full rounded-full bg-indigo-500" style="width: ${pct}%"></div>
        </div>
        <span class="w-10 shrink-0 text-right text-xs font-mono text-slate-600 dark:text-slate-300">${count}</span>
    </div>`;
}

async function loadByTool() {
    try {
        const r = await api('mcp.tool_usage', {});
        const tools = r.tools || [];
        const max = tools.reduce((m, t) => Math.max(m, t.count), 0);

        const chart = document.getElementById('by-tool-chart');
        chart.innerHTML = tools.length
            ? tools.map((t) => barRowHtml(t.tool, t.count, max > 0 ? Math.round(t.count / max * 100) : 0, `${t.tool} (${t.server})`)).join('')
            : `<p class="text-sm text-slate-500">No MCP tool calls recorded yet.</p>`;

        const rows = document.getElementById('by-tool-rows');
        rows.innerHTML = tools.length ? tools.map((t) => {
            const rate = t.count > 0 ? Math.round((t.errors / t.count) * 1000) / 10 : 0;
            return `<tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <td class="px-4 py-2.5"><code class="text-indigo-500 dark:text-indigo-400">${esc(t.tool)}</code></td>
                <td class="px-4 py-2.5 text-xs text-slate-500">${esc(t.server)}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs">${t.count}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs ${t.errors > 0 ? 'text-rose-400' : 'text-slate-500'}">${t.errors}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs ${rate > 0 ? 'text-rose-400' : 'text-slate-500'}">${rate}%</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500">${t.avg_ms != null ? t.avg_ms + ' ms' : '—'}</td>
                <td class="px-4 py-2.5 font-mono text-xs text-slate-500">${esc(t.last_used || '—')}</td>
            </tr>`;
        }).join('') : `<tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No MCP tool calls recorded yet.</td></tr>`;

        // "By Server" reuses this same response, grouped client-side.
        const byServer = {};
        tools.forEach((t) => {
            byServer[t.server] = byServer[t.server] || { server: t.server, count: 0, errors: 0, tools: 0 };
            byServer[t.server].count += t.count;
            byServer[t.server].errors += t.errors;
            byServer[t.server].tools += 1;
        });
        const servers = Object.values(byServer).sort((a, b) => b.count - a.count);
        const maxServer = servers.reduce((m, s) => Math.max(m, s.count), 0);
        const cardsHost = document.getElementById('by-server-cards');
        cardsHost.innerHTML = servers.length ? servers.map((s) => {
            const pct = maxServer > 0 ? Math.round(s.count / maxServer * 100) : 0;
            return `<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
                <p class="text-xs font-mono text-slate-400">${esc(s.server)}</p>
                <p class="text-3xl font-bold mt-1">${s.count} <span class="text-sm font-normal text-slate-400">calls</span></p>
                <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden mt-3">
                    <div class="h-full rounded-full bg-indigo-500" style="width: ${pct}%"></div>
                </div>
                <div class="flex items-center justify-between mt-2 text-xs text-slate-500">
                    <span>${s.tools} tool(s)</span>
                    <span class="${s.errors > 0 ? 'text-rose-400' : ''}">${s.errors} error(s)</span>
                </div>
            </div>`;
        }).join('') : `<p class="text-sm text-slate-500 col-span-full">No MCP tool calls recorded yet.</p>`;
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function loadByTicket() {
    try {
        const r = await api('mcp.by_ticket', { limit: 50 });
        const tickets = r.tickets || [];
        const rows = document.getElementById('by-ticket-rows');
        rows.innerHTML = tickets.length ? tickets.map((t) => {
            const key = `TF-${String(t.task_id).padStart(3, '0')}`;
            const title = esc(key + (t.task_title ? ' - ' + t.task_title : ''));
            return `<tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40 cursor-pointer" data-task-id="${t.task_id}" data-task-title="${title}" title="View session details">
                <td class="px-4 py-2.5"><span class="font-mono text-xs text-slate-400">${key}</span> ${esc(t.task_title || '(untitled)')}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs">${t.calls}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs ${t.errors > 0 ? 'text-rose-400' : 'text-slate-500'}">${t.errors}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-500">${(t.total_ms / 1000).toFixed(1)} s</td>
            </tr>`;
        }).join('') : `<tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No ticket-linked calls recorded yet.</td></tr>`;
    } catch (err) {
        toast(err.message, 'error');
    }
}

async function loadTimeline() {
    try {
        const r = await api('mcp.timeline', { days: 14 });
        const days = r.days || [];
        const max = days.reduce((m, d) => Math.max(m, d.count), 0) || 1;
        const bars = document.querySelector('#timeline-chart > div:first-child');
        const labels = document.querySelector('#timeline-chart > div:last-child');
        bars.innerHTML = days.map((d) => {
            const h = d.count > 0 ? Math.max(Math.round(d.count / max * 100), 4) : 0;
            return `<div class="flex-1 h-full flex flex-col justify-end items-center" title="${esc(d.day)}: ${d.count} call(s)">
                <div class="w-full rounded-t bg-indigo-500" style="height: ${h}%"></div>
            </div>`;
        }).join('');
        labels.innerHTML = days.map((d) => {
            const dt = new Date(d.day + 'T00:00:00');
            const label = isNaN(dt) ? d.day : dt.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
            return `<div class="flex-1 text-center text-[9px] text-slate-400">${esc(label)}</div>`;
        }).join('');
    } catch (err) {
        toast(err.message, 'error');
    }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
