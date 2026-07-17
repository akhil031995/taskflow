<?php
/**
 * Renders a single Kanban task card.
 * Expects $task in scope. Used by project.php (server render) and mirrored
 * by the buildCard() function in assets/project.js (client render).
 */
?>
<div class="task-card group relative bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm cursor-grab active:cursor-grabbing overflow-hidden flex"
     data-id="<?= (int) $task['id'] ?>"
     data-title="<?= e($task['title']) ?>"
     data-description="<?= e($task['description']) ?>"
     data-priority="<?= e($task['priority']) ?>"
     data-status="<?= e($task['status']) ?>">
    <!-- Color-coded priority strip -->
    <span class="w-1.5 shrink-0 <?= PRIORITY_COLORS[$task['priority']] ?>"></span>
    <div class="p-3 flex-1 min-w-0">
        <p class="font-medium text-sm truncate"><?= e($task['title']) ?></p>
        <?php if (!empty($task['description'])): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"><?= e($task['description']) ?></p>
        <?php endif; ?>
    </div>
    <button class="edit-task opacity-0 group-hover:opacity-100 absolute top-2 right-2 text-gray-400 hover:text-indigo-600 text-xs">✎</button>
</div>
