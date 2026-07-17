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

try {
    switch ($action) {
        // ---- Tasks -------------------------------------------------------
        case 'task.create':  json_response(task_create());  break;
        case 'task.update':  json_response(task_update());  break;
        case 'task.move':    json_response(task_move());    break;
        case 'task.delete':  json_response(task_delete());  break;

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

    $status   = validate_status($in['status'] ?? 'pending');
    $priority = validate_priority($in['priority'] ?? 'medium');

    $stmt = db()->prepare(
        'INSERT INTO tasks (project_id, title, description, status, priority, position)
         VALUES (?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([
        $projectId,
        $title,
        trim((string) ($in['description'] ?? '')),
        $status,
        $priority,
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
        'UPDATE tasks SET title = ?, description = ?, priority = ?, status = ? WHERE id = ?'
    );
    $stmt->execute([
        trim((string) ($in['title'] ?? '')),
        trim((string) ($in['description'] ?? '')),
        validate_priority($in['priority'] ?? 'medium'),
        validate_status($in['status'] ?? 'pending'),
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

    $stmt = db()->prepare('INSERT INTO projects (name, description, color) VALUES (?, ?, ?)');
    $stmt->execute([$name, trim((string) ($in['description'] ?? '')), $color]);
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

function validate_priority(string $priority): string
{
    return array_key_exists($priority, PRIORITY_COLORS) ? $priority : 'medium';
}
