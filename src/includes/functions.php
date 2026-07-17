<?php
/**
 * Shared helpers used by both page views and the API router.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/** The four Kanban columns, in display order: db status => human label. */
const TASK_STATUSES = [
    'pending'     => 'Pending',
    'in_progress' => 'In Progress',
    'on_hold'     => 'On Hold',
    'completed'   => 'Completed',
];

/** Priority => Tailwind background class for the color-coded card strip. */
const PRIORITY_COLORS = [
    'urgent' => 'bg-red-500',
    'high'   => 'bg-yellow-500',
    'medium' => 'bg-blue-500',
    'low'    => 'bg-green-500',
];

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

/** Fetch all tasks for a project, ordered for Kanban rendering. */
function get_tasks_for_project(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM tasks WHERE project_id = ? ORDER BY position ASC, id ASC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
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
