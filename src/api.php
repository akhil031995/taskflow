<?php
/**
 * TaskFlow API router.
 *
 * A single lightweight endpoint dispatched by ?action=<name>.
 * Every handler returns JSON and never triggers a full page reload.
 *
 * Actions:
 *   Tasks:  task.create, task.update, task.move, task.delete
 *   Notes:  note.create, note.save (auto-save), note.rename, note.delete
 *   Links:  link.create, link.delete
 *   Projects: project.create, project.delete
 *   Utility:  backup   (streams a .sql or .json dump for download)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$action = $_GET['action'] ?? '';

// The backup action streams a file download, so it is handled before the
// JSON error wrapper below.
if ($action === 'backup') {
    handle_backup();
    exit;
}

// The SSE stream manages its own headers and runs a long-lived loop, so it is
// dispatched before the JSON error wrapper too.
if ($action === 'session.stream') {
    handle_session_stream();
    exit;
}

try {
    switch ($action) {
        // ---- Tasks -------------------------------------------------------
        case 'task.create':  json_response(task_create());  break;
        case 'task.update':  json_response(task_update());  break;
        case 'task.move':    json_response(task_move());    break;
        case 'task.delete':  json_response(task_delete());  break;
        case 'task.criteria': json_response(task_criteria()); break;
        case 'task.get':     json_response(task_get());     break;

        // ---- Notes -------------------------------------------------------
        case 'note.create':  json_response(note_create());  break;
        case 'note.save':    json_response(note_save());    break;
        case 'note.rename':  json_response(note_rename());  break;
        case 'note.delete':  json_response(note_delete());  break;

        // ---- Quick links -------------------------------------------------
        case 'link.create':  json_response(link_create());  break;
        case 'link.delete':  json_response(link_delete());  break;

        // ---- Projects ----------------------------------------------------
        case 'project.create': json_response(project_create()); break;
        case 'project.delete': json_response(project_delete()); break;

        // ---- Standards (org baseline + per-project override) -------------
        case 'project.standards.get':  json_response(project_standards_get());  break;
        case 'project.standards.save': json_response(project_standards_save()); break;

        // ---- Definition-of-Done gates (per-project lint/test/build) ------
        case 'project.dod.get':  json_response(project_dod_get());  break;
        case 'project.dod.save': json_response(project_dod_save()); break;

        // ---- Control plane -----------------------------------------------
        case 'settings.save':          json_response(settings_save());          break;
        case 'settings.test.telegram': json_response(settings_test_telegram()); break;
        case 'mcp.logs':        json_response(mcp_logs());        break;
        case 'mcp.runs':        json_response(mcp_runs());        break;
        case 'mcp.tool_usage':  json_response(mcp_tool_usage());   break;
        case 'mcp.by_ticket':   json_response(mcp_by_ticket());    break;
        case 'mcp.timeline':    json_response(mcp_timeline());     break;
        case 'fs.list':         json_response(fs_list());          break;

        // ---- Live sessions -----------------------------------------------
        case 'sessions.active': json_response(sessions_active()); break;
        case 'session.log':     json_response(session_log());     break;

        default:
            json_response(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $ex) {
    // Never leak stack traces; return a graceful JSON error the frontend
    // can surface as a toast.
    json_response(['ok' => false, 'error' => 'Server error: ' . $ex->getMessage()], 500);
}

// ===================================================================
// Task handlers
// ===================================================================

function task_create(): array
{
    $in = json_input();
    $projectId = (int) ($in['project_id'] ?? 0);
    $title     = trim((string) ($in['title'] ?? ''));

    if ($projectId <= 0 || $title === '') {
        json_response(['ok' => false, 'error' => 'project_id and title are required'], 422);
    }

    $status    = validate_status($in['status'] ?? 'pending');
    $priority  = validate_priority($in['priority'] ?? 2);
    $taskType  = validate_task_type($in['task_type'] ?? 'feature');
    $createdBy = (($in['created_by'] ?? 'human') === 'ai') ? 'ai' : 'human';

    // Enforce an acceptance-criteria template: never save a ticket without one.
    // Falls back to this project's own DoD override before the org default.
    $criteria = trim((string) ($in['acceptance_criteria'] ?? ''));
    if ($criteria === '') {
        $criteria = effective_acceptance_criteria($projectId);
    }

    $stmt = db()->prepare(
        'INSERT INTO tasks
            (project_id, title, description, status, priority, position,
             task_type, acceptance_criteria, created_by, ai_execution_status)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $projectId,
        $title,
        trim((string) ($in['description'] ?? '')),
        $status,
        $priority,
        $taskType,
        $criteria,
        $createdBy,
        'pending',
    ]);

    $id = (int) db()->lastInsertId();
    return ['ok' => true, 'id' => $id];
}

function task_update(): array
{
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }

    $stmt = db()->prepare(
        'UPDATE tasks
            SET title = ?, description = ?, priority = ?, status = ?,
                task_type = ?, acceptance_criteria = ?
          WHERE id = ?'
    );
    $stmt->execute([
        trim((string) ($in['title'] ?? '')),
        trim((string) ($in['description'] ?? '')),
        validate_priority($in['priority'] ?? 2),
        validate_status($in['status'] ?? 'pending'),
        validate_task_type($in['task_type'] ?? 'feature'),
        trim((string) ($in['acceptance_criteria'] ?? '')),
        $id,
    ]);

    return ['ok' => true];
}

/**
 * Drag-and-drop handler: moves a task to a new column and/or position.
 * `order` is the array of task IDs in the destination column, top-to-bottom,
 * so we can persist ordering in one shot.
 */
function task_move(): array
{
    $in     = json_input();
    $id     = (int) ($in['id'] ?? 0);
    $status = validate_status($in['status'] ?? '');
    $order  = $in['order'] ?? [];

    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Update the moved task's column.
    $stmt = $pdo->prepare('UPDATE tasks SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);

    // Persist the new ordering of the destination column.
    if (is_array($order)) {
        $pos = 0;
        $posStmt = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ?');
        foreach ($order as $taskId) {
            $posStmt->execute([$pos++, (int) $taskId]);
        }
    }

    $pdo->commit();
    return ['ok' => true];
}

function task_delete(): array
{
    $id = (int) (json_input()['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    return ['ok' => true];
}

/** Full task detail for the detail modal (task + project + parsed comments/criteria). */
function task_get(): array
{
    $id = (int) (json_input()['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $t = get_task($id);
    if (!$t) {
        json_response(['ok' => false, 'error' => 'Task not found'], 404);
    }
    $p  = get_project((int) $t['project_id']);
    $pk = priority_key($t['priority'] ?? 2);

    return [
        'ok'             => true,
        'task'           => $t,           // timestamps already IST via the +05:30 session
        'key'            => ticket_key($id),
        'priority_label' => PRIORITY_PILLS[$pk] ?? '',
        'priority_class' => PRIORITY_PILL_CLASSES[$pk] ?? '',
        'type_label'     => ucwords(str_replace('-', ' ', $t['task_type'] ?? 'feature')),
        'comments'       => parse_ai_comments($t['ai_comments'] ?? ''),
        'criteria'       => parse_acceptance_criteria($t['acceptance_criteria'] ?? ''),
        'project'        => $p ? [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'color'       => $p['color'],
            'folder_path' => $p['folder_path'],
            'access_url'  => $p['access_url'],
        ] : null,
    ];
}

/** Persist an acceptance-criteria checklist edit from the card. */
function task_criteria(): array
{
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $stmt = db()->prepare('UPDATE tasks SET acceptance_criteria = ? WHERE id = ?');
    $stmt->execute([(string) ($in['acceptance_criteria'] ?? ''), $id]);
    return ['ok' => true];
}

// ===================================================================
// Control-plane handlers (settings + MCP logs)
// ===================================================================

/** Persist a batch of whitelisted settings. */
function settings_save(): array
{
    $in       = json_input();
    $settings = $in['settings'] ?? [];
    if (!is_array($settings)) {
        json_response(['ok' => false, 'error' => 'settings object required'], 422);
    }

    // Only known keys may be written from the UI.
    $allowed = [
        'mcp_poll_interval_minutes',
        'mcp_server_command',
        'mcp_enabled',
        'agent_system_prompt',
        'agent_operating_rules',
        'host_projects_root',
        'org_standards_baseline',
        'notify_webhook_enabled',
        'notify_webhook_url',
        'notify_telegram_enabled',
        'notify_telegram_bot_token',
        'notify_telegram_chat_id',
        'notify_email_enabled',
        'notify_email_smtp_host',
        'notify_email_smtp_port',
        'notify_email_smtp_user',
        'notify_email_smtp_pass',
        'notify_email_smtp_secure',
        'notify_email_from',
        'notify_email_to',
        'notify_app_base_url',
        'model_routing_enabled',
        'model_routing_default_model',
        'model_routing_priority_1',
        'model_routing_priority_2',
        'model_routing_priority_3',
        'model_routing_task_type_overrides',
    ];

    if (isset($settings['model_routing_task_type_overrides']) && trim((string) $settings['model_routing_task_type_overrides']) !== '') {
        json_decode((string) $settings['model_routing_task_type_overrides']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_response(['ok' => false, 'error' => 'model_routing_task_type_overrides must be valid JSON'], 422);
        }
    }

    $saved = 0;
    foreach ($settings as $key => $value) {
        if (in_array($key, $allowed, true)) {
            set_setting($key, is_scalar($value) ? (string) $value : json_encode($value));
            $saved++;
        }
    }
    return ['ok' => true, 'saved' => $saved];
}

/**
 * Sends a dummy "TF-00" update message to the given Telegram chat, using the
 * bot token / chat ID currently in the settings form (not necessarily saved
 * yet), so a user can verify the connection before persisting it.
 */
function settings_test_telegram(): array
{
    $in       = json_input();
    $botToken = trim((string) ($in['bot_token'] ?? ''));
    $chatId   = trim((string) ($in['chat_id'] ?? ''));

    if ($botToken === '' || $chatId === '') {
        json_response(['ok' => false, 'error' => 'Bot token and chat ID are required'], 422);
    }

    $text = "TaskFlow TF-00 Test connection\nThis is a dummy update message confirming your Telegram bot is configured correctly.";

    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'text' => $text]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => "Request failed: {$curlErr}"];
    }

    $decoded = json_decode($response, true);
    if ($httpCode !== 200 || !($decoded['ok'] ?? false)) {
        return ['ok' => false, 'error' => $decoded['description'] ?? "Telegram API responded {$httpCode}"];
    }

    return ['ok' => true];
}

/**
 * Server-side directory browser for the project folder picker.
 * Lists subdirectories under the mounted projects root ($WORKSPACES_DIR),
 * translating container paths back to the real host path for storage.
 * Traversal-guarded: never escapes the mounted base.
 */
function fs_list(): array
{
    $base     = rtrim(getenv('WORKSPACES_DIR') ?: '/workspaces', '/');
    $hostRoot = rtrim(get_setting('host_projects_root', getenv('HOST_PROJECTS_ROOT') ?: '/home/akhil/development'), '/');

    if (!is_dir($base)) {
        json_response(['ok' => false, 'error' => 'Projects root is not mounted at ' . $base . '. Add the volume and restart.'], 400);
    }

    $rel    = ltrim((string) (json_input()['path'] ?? ''), '/');
    $target = $rel === '' ? $base : $base . '/' . $rel;
    $real   = realpath($target);

    // Reject anything that resolves outside the mounted base.
    if ($real === false || strpos($real . '/', $base . '/') !== 0) {
        json_response(['ok' => false, 'error' => 'Path not accessible'], 400);
    }

    $dirs = [];
    foreach (scandir($real) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue; // skip dotfiles / dotdirs
        }
        if (is_dir($real . '/' . $entry)) {
            $dirs[] = $entry;
        }
    }
    sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);

    $relClean = ltrim(substr($real, strlen($base)), '/');
    $hostPath = $relClean === '' ? $hostRoot : $hostRoot . '/' . $relClean;

    $parent = null;
    if ($relClean !== '') {
        $d = dirname($relClean);
        $parent = ($d === '.') ? '' : $d;
    }

    return [
        'ok'        => true,
        'rel'       => $relClean,
        'host_path' => $hostPath,
        'parent'    => $parent,   // null when at the root
        'dirs'      => $dirs,
    ];
}

// ===================================================================
// Live session monitoring
// ===================================================================

/** List active / initializing / paused sessions for the selector panel. */
function sessions_active(): array
{
    $rows = get_active_sessions(30);
    $sessions = array_map(function ($r) {
        $meta = SESSION_STATUS_META[$r['status']] ?? ['label' => strtoupper($r['status']), 'dot' => 'bg-slate-400', 'pulse' => false];
        return [
            'id'            => (int) $r['id'],
            'key'           => ticket_key($r['id']),
            'title'         => $r['title'],
            'status'        => $r['status'],
            'label'         => $meta['label'],
            'dot'           => $meta['dot'],
            'pulse'         => $meta['pulse'],
            'project_id'    => (int) $r['project_id'],
            'project_name'  => $r['project_name'],
            'last_activity' => $r['last_activity'],
        ];
    }, $rows);
    return ['ok' => true, 'sessions' => $sessions];
}

/**
 * Ingest a live log entry from the MCP server or CLI wrapper.
 * Body: { task_id, log_type, content, file_path? }
 */
function session_log(): array
{
    $in      = json_input();
    $taskId  = (int) ($in['task_id'] ?? 0);
    $logType = (string) ($in['log_type'] ?? '');
    $content = (string) ($in['content'] ?? '');

    if ($taskId <= 0 || !in_array($logType, SESSION_LOG_TYPES, true)) {
        json_response(['ok' => false, 'error' => 'task_id and a valid log_type are required'], 422);
    }

    $stmt = db()->prepare(
        'INSERT INTO ai_session_logs (task_id, log_type, content, file_path) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $taskId,
        $logType,
        $content,
        ($in['file_path'] ?? null) ? (string) $in['file_path'] : null,
    ]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/**
 * Server-Sent Events stream of session logs for one task.
 * Emits each new ai_session_logs row as it appears. Bounded runtime; the
 * browser's EventSource reconnects automatically (resuming via Last-Event-ID).
 */
function handle_session_stream(): void
{
    $taskId = (int) ($_GET['task_id'] ?? 0);
    $lastId = (int) ($_GET['last_id'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0));

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // ask proxies (nginx) not to buffer

    // Flush any output buffering so events reach the browser immediately.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    if ($taskId <= 0) {
        echo "event: error\ndata: {\"error\":\"task_id required\"}\n\n";
        return;
    }

    @set_time_limit(0);
    $deadline = time() + 60; // bounded; client reconnects after this

    // Tell the client how quickly to retry on disconnect.
    echo "retry: 3000\n\n";

    while (time() < $deadline) {
        $rows = get_session_logs($taskId, $lastId, 200);
        foreach ($rows as $row) {
            $lastId = (int) $row['id'];
            echo 'id: ' . $lastId . "\n";
            echo 'event: log' . "\n";
            echo 'data: ' . json_encode($row) . "\n\n";
        }
        if (empty($rows)) {
            echo ": keepalive\n\n"; // comment frame keeps the connection warm
        }
        if (connection_aborted()) {
            break;
        }
        @flush();
        sleep(2);
    }
}

/** Return recent MCP invocations for the live logs table, optionally scoped to one run. */
function mcp_logs(): array
{
    $in    = json_input();
    $limit = (int) ($in['limit'] ?? 100);
    $tool  = trim((string) ($in['tool'] ?? ''));
    $runId = trim((string) ($in['run_id'] ?? ''));
    $stats = get_mcp_log_stats();
    return [
        'ok'    => true,
        'total' => $stats['total'],
        'logs'  => get_mcp_logs($limit, $tool, $runId),
    ];
}

/** Return recent runs for the logs screen's run filter dropdown. */
function mcp_runs(): array
{
    $in    = json_input();
    $limit = (int) ($in['limit'] ?? 50);
    return ['ok' => true, 'runs' => get_runs($limit)];
}

/** Tool-usage breakdown (dashboard card top-N, or the Logs "By Tool"/"By Server" tabs). */
function mcp_tool_usage(): array
{
    $in    = json_input();
    $limit = isset($in['limit']) && $in['limit'] !== '' ? (int) $in['limit'] : null;
    return ['ok' => true, 'tools' => get_tool_usage_stats($limit)];
}

/** Call counts grouped by ticket, for the Logs "By Ticket" tab. */
function mcp_by_ticket(): array
{
    $in    = json_input();
    $limit = (int) ($in['limit'] ?? 50);
    return ['ok' => true, 'tickets' => get_ticket_usage_stats($limit)];
}

/** Daily call-volume timeline, for the Logs "Timeline" tab. */
function mcp_timeline(): array
{
    $in   = json_input();
    $days = (int) ($in['days'] ?? 14);
    return ['ok' => true, 'days' => get_tool_usage_timeline($days)];
}

// ===================================================================
// Note handlers
// ===================================================================

function note_create(): array
{
    $in        = json_input();
    $projectId = (int) ($in['project_id'] ?? 0);
    if ($projectId <= 0) {
        json_response(['ok' => false, 'error' => 'project_id is required'], 422);
    }

    // New tab goes to the end.
    $posStmt = db()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM notes WHERE project_id = ?');
    $posStmt->execute([$projectId]);
    $position = (int) $posStmt->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO notes (project_id, title, content, position) VALUES (?, ?, "", ?)'
    );
    $title = trim((string) ($in['title'] ?? '')) ?: 'New Note';
    $stmt->execute([$projectId, $title, $position]);

    return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'title' => $title];
}

/** Auto-save endpoint: persists Quill HTML for a note. */
function note_save(): array
{
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $stmt = db()->prepare('UPDATE notes SET content = ? WHERE id = ?');
    $stmt->execute([(string) ($in['content'] ?? ''), $id]);

    return ['ok' => true, 'saved_at' => date('c')];
}

function note_rename(): array
{
    $in = json_input();
    $id = (int) ($in['id'] ?? 0);
    $title = trim((string) ($in['title'] ?? ''));
    if ($id <= 0 || $title === '') {
        json_response(['ok' => false, 'error' => 'id and title are required'], 422);
    }
    $stmt = db()->prepare('UPDATE notes SET title = ? WHERE id = ?');
    $stmt->execute([$title, $id]);
    return ['ok' => true];
}

function note_delete(): array
{
    $id = (int) (json_input()['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $stmt = db()->prepare('DELETE FROM notes WHERE id = ?');
    $stmt->execute([$id]);
    return ['ok' => true];
}

// ===================================================================
// Quick link handlers
// ===================================================================

function link_create(): array
{
    $in    = json_input();
    $title = trim((string) ($in['title'] ?? ''));
    $url   = trim((string) ($in['url'] ?? ''));

    if ($title === '' || $url === '') {
        json_response(['ok' => false, 'error' => 'title and url are required'], 422);
    }
    // Normalize a bare domain into a valid URL.
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    $stmt = db()->prepare('INSERT INTO quick_links (title, url) VALUES (?, ?)');
    $stmt->execute([$title, $url]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'title' => $title, 'url' => $url];
}

function link_delete(): array
{
    $id = (int) (json_input()['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    $stmt = db()->prepare('DELETE FROM quick_links WHERE id = ?');
    $stmt->execute([$id]);
    return ['ok' => true];
}

// ===================================================================
// Project handlers
// ===================================================================

function project_create(): array
{
    $in   = json_input();
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        json_response(['ok' => false, 'error' => 'name is required'], 422);
    }
    $color = (string) ($in['color'] ?? '#3b82f6');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#3b82f6';
    }

    // Optional workspace metadata: where the agent implements + how to reach it.
    $folder = trim((string) ($in['folder_path'] ?? '')) ?: null;
    $url    = trim((string) ($in['access_url'] ?? ''));
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    $url = $url !== '' ? $url : null;

    $stmt = db()->prepare(
        'INSERT INTO projects (name, description, color, folder_path, access_url)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, trim((string) ($in['description'] ?? '')), $color, $folder, $url]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

function project_delete(): array
{
    $id = (int) (json_input()['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'id is required'], 422);
    }
    // Tasks and notes cascade-delete via foreign keys.
    $stmt = db()->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    return ['ok' => true];
}

// ===================================================================
// Standards handlers (org baseline lives in `settings`, see settings_save();
// per-project override lives in `project_standards`).
// ===================================================================

/** Return a project's override text plus the resolved effective standards. */
function project_standards_get(): array
{
    $id = (int) (json_input()['project_id'] ?? 0);
    if ($id <= 0 || !get_project($id)) {
        json_response(['ok' => false, 'error' => 'valid project_id is required'], 422);
    }
    return [
        'ok'        => true,
        'baseline'  => get_org_standards_baseline(),
        'override'  => get_project_standards_override($id) ?? '',
        'effective' => resolve_effective_standards($id),
    ];
}

/** Save a project's standards override. */
function project_standards_save(): array
{
    $in = json_input();
    $id = (int) ($in['project_id'] ?? 0);
    if ($id <= 0 || !get_project($id)) {
        json_response(['ok' => false, 'error' => 'valid project_id is required'], 422);
    }
    set_project_standards_override($id, (string) ($in['override'] ?? ''));
    return ['ok' => true, 'effective' => resolve_effective_standards($id)];
}

// ===================================================================
// Definition-of-Done gate handlers (per-project lint/test/build commands +
// acceptance-criteria override; run by mcp-server on update_ticket_status).
// ===================================================================

/** Return a project's DoD gate config plus the org default criteria template. */
function project_dod_get(): array
{
    $id = (int) (json_input()['project_id'] ?? 0);
    if ($id <= 0 || !get_project($id)) {
        json_response(['ok' => false, 'error' => 'valid project_id is required'], 422);
    }
    return [
        'ok'                => true,
        'default_criteria'  => ACCEPTANCE_CRITERIA_TEMPLATE,
    ] + get_project_dod_gates($id);
}

/** Save a project's DoD gate config. */
function project_dod_save(): array
{
    $in = json_input();
    $id = (int) ($in['project_id'] ?? 0);
    if ($id <= 0 || !get_project($id)) {
        json_response(['ok' => false, 'error' => 'valid project_id is required'], 422);
    }
    set_project_dod_gates($id, $in);
    return ['ok' => true] + get_project_dod_gates($id);
}

// ===================================================================
// 1-Click backup
// ===================================================================

/**
 * Streams a database backup as a download.
 * ?format=sql (default) produces a portable SQL dump built in pure PHP.
 * ?format=json produces a structured JSON export.
 */
function handle_backup(): void
{
    $format = strtolower($_GET['format'] ?? 'sql');
    $stamp  = date('Y-m-d_His');
    $tables = ['projects', 'tasks', 'notes', 'quick_links'];

    if ($format === 'json') {
        $export = [];
        foreach ($tables as $t) {
            $export[$t] = db()->query("SELECT * FROM {$t}")->fetchAll();
        }
        header('Content-Type: application/json');
        header("Content-Disposition: attachment; filename=\"taskflow_backup_{$stamp}.json\"");
        echo json_encode([
            'generated_at' => date('c'),
            'tables'       => $export,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    // Default: a self-contained SQL dump.
    header('Content-Type: application/sql');
    header("Content-Disposition: attachment; filename=\"taskflow_backup_{$stamp}.sql\"");

    $pdo = db();
    echo "-- TaskFlow backup generated {$stamp}\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Table structure.
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        echo $create[1] . ";\n\n";

        // Table rows.
        $rows = $pdo->query("SELECT * FROM `{$table}`");
        foreach ($rows as $row) {
            $cols = array_map(fn ($c) => "`{$c}`", array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string) $v);
            }, array_values($row));
            echo "INSERT INTO `{$table}` (" . implode(', ', $cols) . ') VALUES ('
                . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
}

// ===================================================================
// Validation helpers
// ===================================================================

function validate_status(string $status): string
{
    return array_key_exists($status, TASK_STATUSES) ? $status : 'pending';
}

/** Numeric priority 1-3, defaulting to 2-Medium. */
function validate_priority($priority): int
{
    $n = (int) $priority;
    return array_key_exists($n, PRIORITY_COLORS) ? $n : 2;
}

/** Task type, defaulting to 'feature'. */
function validate_task_type(string $type): string
{
    return in_array($type, TASK_TYPES, true) ? $type : 'feature';
}
