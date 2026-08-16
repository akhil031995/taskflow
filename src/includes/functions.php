<?php
/**
 * Shared helpers used by both page views and the API router.
 */

declare(strict_types=1);

// App-wide timezone: India Standard Time. Affects every date()/strtotime()
// (backup filenames, AI-comment timestamps, note save times, etc.). The DB
// connection is pinned to +05:30 in config/db.php to match.
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';

/** The four Kanban columns, in display order: db status => human label. */
const TASK_STATUSES = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'on_hold'     => 'On Hold',
    'completed'   => 'Completed',
];

/**
 * Numeric priority (1-High, 2-Medium, 3-Low) => Tailwind background class for
 * the color-coded card strip. Keys are ints to match the TINYINT column.
 */
const PRIORITY_COLORS = [
    1 => 'bg-red-500',
    2 => 'bg-amber-500',
    3 => 'bg-emerald-500',
];

/** Numeric priority => human label. */
const PRIORITY_LABELS = [
    1 => 'High',
    2 => 'Medium',
    3 => 'Low',
];

/** Numeric priority => short pill label shown on cards. */
const PRIORITY_PILLS = [
    1 => 'High Priority',
    2 => 'Med Priority',
    3 => 'Low Priority',
];

/** Numeric priority => pill classes (light/dark aware). */
const PRIORITY_PILL_CLASSES = [
    1 => 'bg-red-500/15 text-red-400 ring-1 ring-red-500/30',
    2 => 'bg-amber-500/15 text-amber-400 ring-1 ring-amber-500/30',
    3 => 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/30',
];

/** Kanban status => pill classes for the compact-view status capsule. */
const STATUS_PILL_CLASSES = [
    'pending'     => 'bg-slate-500/15 text-slate-500 dark:text-slate-300 ring-1 ring-slate-500/30',
    'in_progress' => 'bg-indigo-500/15 text-indigo-500 dark:text-indigo-300 ring-1 ring-indigo-500/30',
    'on_hold'     => 'bg-orange-500/15 text-orange-500 dark:text-orange-300 ring-1 ring-orange-500/30',
    'completed'   => 'bg-emerald-500/15 text-emerald-500 dark:text-emerald-300 ring-1 ring-emerald-500/30',
];

/** AI execution status => pill classes for the board. */
const AI_STATUS_PILL_CLASSES = [
    'in-progress'         => 'bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-500/30',
    'blocked'             => 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30',
    'rate-limited-paused' => 'bg-orange-500/15 text-orange-300 ring-1 ring-orange-500/30',
    'completed'           => 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30',
    'pending'             => 'bg-slate-500/15 text-slate-300 ring-1 ring-slate-500/30',
];

/** Allowed task types (matches the ENUM in migration 003). */
const TASK_TYPES = ['feature', 'bug', 'tech-debt', 'sub-task'];

/** Task type => badge classes (light/dark aware). */
const TASK_TYPE_BADGES = [
    'feature'   => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    'bug'       => 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
    'tech-debt' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    'sub-task'  => 'bg-gray-200 text-gray-700 dark:bg-gray-600/40 dark:text-gray-300',
];

/** Allowed AI execution states (matches the ENUM in migration 003). */
const AI_EXEC_STATUSES = ['pending', 'in-progress', 'completed', 'blocked', 'rate-limited-paused'];

/** Allowed live-session log types (matches the ENUM in migration 006). */
const SESSION_LOG_TYPES = ['thought', 'terminal', 'code_diff', 'status'];

/**
 * Active-session display metadata: status => label + dot color + pulse flag,
 * for the "Active Code Sessions" selector panel.
 */
const SESSION_STATUS_META = [
    'in-progress'         => ['label' => 'RUNNING',             'dot' => 'bg-emerald-400', 'pulse' => true],
    'pending'             => ['label' => 'INITIALIZING',        'dot' => 'bg-amber-400',   'pulse' => false],
    'rate-limited-paused' => ['label' => 'RATE-LIMITED-PAUSED', 'dot' => 'bg-orange-400',  'pulse' => false],
];

/**
 * Default acceptance-criteria template. Enforced on ticket creation when the
 * author leaves the field blank, so every ticket carries a checklist.
 */
const ACCEPTANCE_CRITERIA_TEMPLATE = "- [ ] Requirement implemented as described\n- [ ] Edge cases handled\n- [ ] No regressions in related features\n- [ ] Verified locally";

/**
 * Which MCP server exposes each tool name (there is no `server` column on
 * mcp_invocations - tool names are unique across both servers, so this map
 * is how "By Server" breakdowns are derived). Keep in sync with the tools
 * registered in mcp-server/index.js (taskflow-mcp) and
 * mcp-server/repomap-server.js (taskflow-repomap).
 */
const MCP_TOOL_SERVERS = [
    'get_highest_priority_ticket' => 'taskflow-mcp',
    'update_ticket_status'        => 'taskflow-mcp',
    'add_ticket_comment'          => 'taskflow-mcp',
    'create_ticket'               => 'taskflow-mcp',
    'search_symbols'              => 'taskflow-repomap',
    'search_code'                 => 'taskflow-repomap',
    'get_outline'                 => 'taskflow-repomap',
    'refresh_index'               => 'taskflow-repomap',
];

/** The MCP server a tool belongs to, or 'other' for anything not in the map above. */
function mcp_server_for_tool(string $tool): string
{
    return MCP_TOOL_SERVERS[$tool] ?? 'other';
}

/** Coerce a stored priority to a valid int key (defaults to 2-Medium). */
function priority_key($value): int
{
    $n = (int) $value;
    return array_key_exists($n, PRIORITY_COLORS) ? $n : 2;
}

/** HTML-escape a string for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and stop. */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** Read and decode a JSON request body (falls back to form data). */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/** Fetch every project ordered by most recently updated. */
function get_projects(): array
{
    return db()->query(
        'SELECT * FROM projects ORDER BY updated_at DESC, id DESC'
    )->fetchAll();
}

/** Fetch a single project or null. */
function get_project(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Fetch a single task or null. */
function get_task(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Human comments on a ticket (detail-modal composer), oldest first - the
 * reviewer side of the conversation. Distinct from ai_comments, which is an
 * append-only text log the agent itself writes.
 * @return array<int,array{author:string,text:string,time:string,datetime:string}>
 */
function get_human_comments(int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT author, comment_text, created_at FROM task_comments WHERE task_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$taskId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $ts = strtotime((string) $row['created_at']);
        $out[] = [
            'author'   => $row['author'],
            'text'     => $row['comment_text'],
            'time'     => $ts ? date('H:i', $ts) : '',
            'datetime' => $ts ? date('Y-m-d H:i:s', $ts) : '',
        ];
    }
    return $out;
}

/** True when a task is currently locked by an AI agent run. */
function task_is_ai_locked(array $task): bool
{
    return ($task['ai_execution_status'] ?? '') === 'in-progress';
}

/**
 * Fetch all tasks for a project, ordered for Kanban rendering. Each row is
 * enriched with `run_count`/`cost_usd` (NULL if the ticket has no recorded
 * agent runs yet) via a single aggregate join, to avoid an N+1 query per card.
 */
function get_tasks_for_project(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, r.run_count, r.cost_usd
           FROM tasks t
           LEFT JOIN (
                SELECT task_id, COUNT(*) AS run_count, SUM(total_cost_usd) AS cost_usd
                  FROM runs GROUP BY task_id
           ) r ON r.task_id = t.id
          WHERE t.project_id = ?
          ORDER BY t.position ASC, t.id ASC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** Total recorded agent-run cost/usage for one ticket, or null if it has no runs yet. */
function get_task_run_cost(int $taskId): ?array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS runs, SUM(total_cost_usd) AS cost_usd,
                SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens
           FROM runs WHERE task_id = ?'
    );
    $stmt->execute([$taskId]);
    $row = $stmt->fetch();
    return ($row && (int) $row['runs'] > 0) ? $row : null;
}

/** Total recorded agent-run cost/usage for one project, or null if it has no runs yet. */
function get_project_run_cost(int $projectId): ?array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS runs, SUM(total_cost_usd) AS cost_usd,
                SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens
           FROM runs WHERE project_id = ?'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return ($row && (int) $row['runs'] > 0) ? $row : null;
}

/** Run-cost rollups for every project (dashboard project cards), keyed by project_id. */
function get_all_project_run_costs(): array
{
    $rows = db()->query(
        'SELECT project_id, COUNT(*) AS runs, SUM(total_cost_usd) AS cost_usd
           FROM runs WHERE project_id IS NOT NULL GROUP BY project_id'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['project_id']] = $r;
    }
    return $out;
}

/**
 * Full token/cost breakdown per project (only projects with at least one
 * recorded agent run), ranked by total tokens - the source data for the
 * dashboard's Token Usage card. Unlike get_all_project_run_costs() (cost_usd
 * only, keyed for lookup), this carries input/output/cache token sums and
 * project display fields, ordered for direct rendering.
 */
function get_project_token_usage(): array
{
    $rows = db()->query(
        "SELECT p.id AS project_id, p.name AS project_name, p.color AS project_color,
                COUNT(r.id) AS runs,
                SUM(r.input_tokens) AS input_tokens,
                SUM(r.output_tokens) AS output_tokens,
                SUM(r.cache_creation_tokens) AS cache_creation_tokens,
                SUM(r.cache_read_tokens) AS cache_read_tokens,
                SUM(r.total_cost_usd) AS cost_usd
           FROM runs r
           JOIN projects p ON p.id = r.project_id
          GROUP BY p.id, p.name, p.color
          ORDER BY (SUM(r.input_tokens) + SUM(r.output_tokens)) DESC"
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['project_id']            = (int) $row['project_id'];
        $row['runs']                  = (int) $row['runs'];
        $row['input_tokens']          = (int) $row['input_tokens'];
        $row['output_tokens']         = (int) $row['output_tokens'];
        $row['cache_creation_tokens'] = (int) $row['cache_creation_tokens'];
        $row['cache_read_tokens']     = (int) $row['cache_read_tokens'];
        $row['total_tokens']          = $row['input_tokens'] + $row['output_tokens'];
        $row['cost_usd']              = (float) $row['cost_usd'];
    }
    return $rows;
}

/** Compact number formatting for token counts: 1,284 / 12.9K / 4.2M. */
function format_compact_number(float $n): string
{
    $abs = abs($n);
    if ($abs >= 1_000_000) {
        return number_format($n / 1_000_000, 1) . 'M';
    }
    if ($abs >= 1_000) {
        return number_format($n / 1_000, 1) . 'K';
    }
    return number_format($n, 0);
}

/** Fetch every "in progress" task across all projects (dashboard feed). */
function get_in_progress_tasks(): array
{
    return db()->query(
        "SELECT t.*, p.name AS project_name, p.color AS project_color
           FROM tasks t
           JOIN projects p ON p.id = t.project_id
          WHERE t.status = 'in_progress'
          ORDER BY t.updated_at DESC"
    )->fetchAll();
}

/** Fetch notes for a project ordered by tab position. */
function get_notes_for_project(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM notes WHERE project_id = ? ORDER BY position ASC, id ASC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/** Fetch all quick links. */
function get_quick_links(): array
{
    return db()->query('SELECT * FROM quick_links ORDER BY id ASC')->fetchAll();
}

/** Human ticket key, e.g. TF-006. */
function ticket_key($id): string
{
    return 'TF-' . str_pad((string) (int) $id, 3, '0', STR_PAD_LEFT);
}

/**
 * Parse the ai_comments log into recent entries.
 * Stored format is one entry per line: "[ISO-8601] text".
 * @return array<int,array{time:string,text:string}> newest last
 */
function parse_ai_comments(?string $raw, int $limit = 0): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
            $ts = strtotime($m[1]);
            $out[] = [
                'time'     => $ts ? date('H:i', $ts) : '',
                'datetime' => $ts ? date('Y-m-d H:i:s', $ts) : '',
                'text'     => $m[2],
            ];
        } else {
            $out[] = ['time' => '', 'datetime' => '', 'text' => $line];
        }
    }
    if ($limit > 0 && count($out) > $limit) {
        $out = array_slice($out, -$limit);
    }
    return $out;
}

/**
 * Parse acceptance criteria markdown checkboxes into structured items.
 * Recognises "- [ ] text" and "- [x] text".
 * @return array<int,array{checked:bool,text:string}>
 */
function parse_acceptance_criteria(?string $raw): array
{
    $items = [];
    foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
        if (preg_match('/^\s*[-*]\s*\[([ xX])\]\s*(.*)$/', $line, $m)) {
            $items[] = ['checked' => strtolower($m[1]) === 'x', 'text' => trim($m[2])];
        }
    }
    return $items;
}

/** Fetch a single setting value. */
function get_setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

/** Fetch all settings as an associative array. */
function get_all_settings(): array
{
    $rows = db()->query('SELECT setting_key, value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['setting_key']] = $r['value'];
    }
    return $out;
}

/** Upsert a setting. */
function set_setting(string $key, ?string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$key, $value]);
}

// ===================================================================
// Layered standards: org-wide baseline (settings) + per-project override
// (project_standards). mcp-server/standards.js mirrors resolve_effective_
// standards() in JS so the Node claim flow can write it into the project's
// CLAUDE.md without a PHP round-trip.
// ===================================================================

/** Org-wide baseline standards text (raw, unmerged). */
function get_org_standards_baseline(): string
{
    return get_setting('org_standards_baseline', '') ?? '';
}

/** A single project's override text, or null if none has been set. */
function get_project_standards_override(int $projectId): ?string
{
    $stmt = db()->prepare('SELECT override_md FROM project_standards WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

/** Upsert a project's standards override. */
function set_project_standards_override(int $projectId, ?string $md): void
{
    $stmt = db()->prepare(
        'INSERT INTO project_standards (project_id, override_md) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE override_md = VALUES(override_md)'
    );
    $stmt->execute([$projectId, $md]);
}

/**
 * Merge the org baseline with a project's override into the effective
 * standards document. Resolved fresh on every call (execution time), never
 * cached, so edits to either layer take effect on the next ticket claim.
 */
function resolve_effective_standards(int $projectId): string
{
    $baseline = trim(get_org_standards_baseline());
    $override = trim(get_project_standards_override($projectId) ?? '');

    $parts = [];
    if ($baseline !== '') {
        $parts[] = "## Org Baseline Standards\n\n{$baseline}";
    }
    if ($override !== '') {
        $parts[] = "## Project Overrides\n\n{$override}";
    }
    return implode("\n\n", $parts);
}

// ===================================================================
// Per-project Definition-of-Done gates: optional lint/test/build commands
// plus an optional override of ACCEPTANCE_CRITERIA_TEMPLATE. Gates are run
// by mcp-server (mcp-server/dod-gates.js) when the agent calls
// update_ticket_status(completed); a project with no commands configured
// simply skips those gates rather than failing them.
// ===================================================================

/** A project's DoD gate config; all fields null/'' when nothing is configured. */
function get_project_dod_gates(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT lint_cmd, test_cmd, build_cmd, criteria_md FROM project_dod_gates WHERE project_id = ?'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return [
        'lint_cmd'    => $row['lint_cmd'] ?? '',
        'test_cmd'    => $row['test_cmd'] ?? '',
        'build_cmd'   => $row['build_cmd'] ?? '',
        'criteria_md' => $row['criteria_md'] ?? '',
    ];
}

/** Upsert a project's DoD gate config. Blank strings are stored as NULL (gate skipped). */
function set_project_dod_gates(int $projectId, array $in): void
{
    $norm = static fn ($v) => trim((string) $v) === '' ? null : trim((string) $v);
    $stmt = db()->prepare(
        'INSERT INTO project_dod_gates (project_id, lint_cmd, test_cmd, build_cmd, criteria_md)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE lint_cmd = VALUES(lint_cmd), test_cmd = VALUES(test_cmd),
             build_cmd = VALUES(build_cmd), criteria_md = VALUES(criteria_md)'
    );
    $stmt->execute([
        $projectId,
        $norm($in['lint_cmd'] ?? ''),
        $norm($in['test_cmd'] ?? ''),
        $norm($in['build_cmd'] ?? ''),
        $norm($in['criteria_md'] ?? ''),
    ]);
}

/**
 * The acceptance-criteria checklist to apply for a new ticket in this
 * project: the project's own override if it has set one, else the org-wide
 * ACCEPTANCE_CRITERIA_TEMPLATE default.
 */
function effective_acceptance_criteria(int $projectId): string
{
    $override = trim(get_project_dod_gates($projectId)['criteria_md']);
    return $override !== '' ? $override : ACCEPTANCE_CRITERIA_TEMPLATE;
}

/**
 * Recent MCP invocations for the logs screen (created_at rendered in IST).
 * $runId filters to one run-agent.sh execution: a numeric `runs.id`, the
 * literal 'unassigned' for historical rows predating run_id tagging
 * (migration 011_run_tagging.sql) where m.run_id IS NULL, or '' for all runs.
 */
function get_mcp_logs(int $limit = 100, string $tool = '', string $runId = ''): array
{
    $limit = max(1, min($limit, 500));
    // created_at is read through the +05:30 session, so this is already IST.
    $cols = 'm.id, m.tool, m.task_id, m.run_id, m.params, m.status, m.result, m.duration_ms,
             DATE_FORMAT(m.created_at, "%Y-%m-%d %H:%i:%s") AS created_at,
             t.title AS task_title';
    $from = 'FROM mcp_invocations m LEFT JOIN tasks t ON t.id = m.task_id';
    $where  = [];
    $params = [];
    if ($tool !== '') {
        $where[]  = 'm.tool = ?';
        $params[] = $tool;
    }
    if ($runId === 'unassigned') {
        $where[] = 'm.run_id IS NULL';
    } elseif ($runId !== '') {
        $where[]  = 'm.run_id = ?';
        $params[] = (int) $runId;
    }
    $sql = "SELECT {$cols} {$from}"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY m.id DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Recent runs (one row per run-agent.sh execution) for the Logs UI's run
 * filter: id, task/project context, timing, exit_code/outcome, and
 * tokens/cost. A run with finished_at still NULL is in progress.
 */
function get_runs(int $limit = 50): array
{
    $limit = max(1, min($limit, 200));
    return db()->query(
        'SELECT r.id, r.task_id, r.project_id, t.title AS task_title,
                DATE_FORMAT(r.started_at, "%Y-%m-%d %H:%i:%s") AS started_at,
                DATE_FORMAT(r.finished_at, "%Y-%m-%d %H:%i:%s") AS finished_at,
                r.exit_code, r.outcome, r.total_cost_usd,
                r.input_tokens, r.output_tokens
           FROM runs r
           LEFT JOIN tasks t ON t.id = r.task_id
          ORDER BY r.id DESC
          LIMIT ' . $limit
    )->fetchAll();
}

/** Aggregate stats for the logs header. */
function get_mcp_log_stats(): array
{
    $pdo = db();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM mcp_invocations')->fetchColumn();
    $errors = (int) $pdo->query("SELECT COUNT(*) FROM mcp_invocations WHERE status = 'error'")->fetchColumn();
    $last = $pdo->query('SELECT created_at FROM mcp_invocations ORDER BY id DESC LIMIT 1')->fetchColumn();
    return ['total' => $total, 'errors' => $errors, 'last' => $last ?: null];
}

/**
 * All-time call counts per tool (both MCP servers), ranked highest-first -
 * the source data for the dashboard's Tool Usage card and the Logs screen's
 * "By Tool" / "By Server" tabs (the latter groups these same rows by the
 * `server` field, no separate query). Pass $limit for a top-N cut (dashboard);
 * omit it for the full breakdown (Logs tab).
 */
function get_tool_usage_stats(?int $limit = null): array
{
    $sql = "SELECT tool, COUNT(*) AS count,
                   SUM(status = 'error') AS errors,
                   ROUND(AVG(duration_ms)) AS avg_ms,
                   DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i:%s') AS last_used
              FROM mcp_invocations
             GROUP BY tool
             ORDER BY count DESC, tool ASC";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['count']  = (int) $row['count'];
        $row['errors'] = (int) $row['errors'];
        $row['avg_ms'] = $row['avg_ms'] !== null ? (int) $row['avg_ms'] : null;
        $row['server'] = mcp_server_for_tool($row['tool']);
    }
    return $rows;
}

/**
 * Call counts grouped by ticket (tickets with at least one recorded
 * invocation), for the Logs screen's "By Ticket" tab. Calls not tied to a
 * ticket (task_id NULL - e.g. repo-map calls, or a claim that found nothing)
 * are excluded here; they still count toward the overall total.
 */
function get_ticket_usage_stats(int $limit = 50): array
{
    $limit = max(1, min($limit, 200));
    $rows = db()->query(
        "SELECT m.task_id, t.title AS task_title,
                COUNT(*) AS calls,
                SUM(m.status = 'error') AS errors,
                SUM(m.duration_ms) AS total_ms
           FROM mcp_invocations m
           LEFT JOIN tasks t ON t.id = m.task_id
          WHERE m.task_id IS NOT NULL
          GROUP BY m.task_id, t.title
          ORDER BY calls DESC
          LIMIT " . $limit
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['task_id']  = (int) $row['task_id'];
        $row['calls']    = (int) $row['calls'];
        $row['errors']   = (int) $row['errors'];
        $row['total_ms'] = (int) $row['total_ms'];
    }
    return $rows;
}

/**
 * Daily call volume for the last $days days (today inclusive), for the Logs
 * screen's Timeline tab. Days with zero calls are filled in with count=0 so
 * the chart always shows a continuous run of days, not just the ones with data.
 */
function get_tool_usage_timeline(int $days = 14): array
{
    $days = max(1, min($days, 90));
    $rows = db()->query(
        "SELECT DATE(created_at) AS day, COUNT(*) AS count
           FROM mcp_invocations
          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)
          GROUP BY DATE(created_at)"
    )->fetchAll();
    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['day']] = (int) $r['count'];
    }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $out[] = ['day' => $day, 'count' => $byDay[$day] ?? 0];
    }
    return $out;
}

/**
 * Active AI sessions for the selector panel: ONLY sessions an agent is actually
 * engaged in - running (in-progress) or paused (rate-limited). Plain pending
 * backlog is not a session and is excluded.
 */
function get_active_sessions(int $limit = 30): array
{
    $limit = max(1, min($limit, 100));
    return db()->query(
        "SELECT t.id, t.title, t.ai_execution_status AS status, t.project_id,
                p.name AS project_name,
                COALESCE(
                    (SELECT MAX(created_at) FROM ai_session_logs l WHERE l.task_id = t.id),
                    t.ai_locked_at, t.updated_at
                ) AS last_activity
           FROM tasks t
           JOIN projects p ON p.id = t.project_id
          WHERE t.ai_execution_status IN ('in-progress', 'rate-limited-paused')
          ORDER BY FIELD(t.ai_execution_status, 'in-progress', 'rate-limited-paused'),
                   last_activity DESC
          LIMIT " . $limit
    )->fetchAll();
}

/** Session logs for one task since a given id (for the initial SSE backfill). */
function get_session_logs(int $taskId, int $sinceId = 0, int $limit = 500): array
{
    $limit = max(1, min($limit, 2000));
    // created_at is IST (via the +05:30 session). `t` is the IST wall-clock
    // time the drawer displays directly, so it never depends on the viewer's
    // browser timezone.
    $stmt = db()->prepare(
        'SELECT id, task_id, log_type, content, file_path,
                DATE_FORMAT(created_at, "%Y-%m-%d %H:%i:%s") AS created_at,
                DATE_FORMAT(created_at, "%H:%i:%s") AS t
           FROM ai_session_logs
          WHERE task_id = ? AND id > ?
          ORDER BY id ASC
          LIMIT ' . $limit
    );
    $stmt->execute([$taskId, $sinceId]);
    return $stmt->fetchAll();
}

/** High-level dashboard stats. */
function get_stats(): array
{
    $pdo = db();
    $totalProjects = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $totalTasks    = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    $completed     = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'")->fetchColumn();
    $completion    = $totalTasks > 0 ? (int) round($completed / $totalTasks * 100) : 0;

    return [
        'projects'   => $totalProjects,
        'tasks'      => $totalTasks,
        'completed'  => $completed,
        'completion' => $completion,
    ];
}
