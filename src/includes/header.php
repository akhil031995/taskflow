<?php
/**
 * Shared page shell: <head>, Tailwind/CDN setup, and the persistent navbar
 * (brand, Quick Links dropdown, 1-Click Backup, Dark Mode toggle).
 *
 * Expects an optional $pageTitle variable set by the including page.
 */
require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'TaskFlow';
$quickLinks = get_quick_links();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · TaskFlow</title>

    <!-- Tailwind via CDN with class-based dark mode. -->
    <script>
        // Apply the saved theme *before* Tailwind loads to avoid a flash.
        if (localStorage.getItem('taskflow-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>

    <!-- SortableJS for Kanban drag-and-drop. -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

    <!-- Quill WYSIWYG for project notes. -->
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
</head>
<body class="h-full bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-100 transition-colors">

<!-- ============================ NAVBAR ============================ -->
<nav class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 flex items-center justify-between h-14">
        <!-- Brand -->
        <a href="index.php" class="flex items-center gap-2 font-bold text-lg">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white">✓</span>
            <span>TaskFlow</span>
        </a>

        <!-- Right-side actions -->
        <div class="flex items-center gap-2">

            <!-- Quick Links dropdown -->
            <div class="relative" id="ql-wrapper">
                <button id="ql-toggle" type="button"
                    class="px-3 py-1.5 rounded-md text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center gap-1">
                    🔗 Quick Links <span class="text-xs">▾</span>
                </button>
                <div id="ql-menu"
                    class="hidden absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg p-2">
                    <ul id="ql-list" class="max-h-64 overflow-auto text-sm">
                        <?php foreach ($quickLinks as $link): ?>
                            <li class="flex items-center justify-between gap-2 group px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                data-id="<?= (int) $link['id'] ?>">
                                <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"
                                   class="truncate text-indigo-600 dark:text-indigo-400 hover:underline"><?= e($link['title']) ?></a>
                                <button class="ql-del opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500"
                                        title="Delete">✕</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="border-t border-gray-200 dark:border-gray-700 mt-2 pt-2 space-y-1">
                        <input id="ql-title" type="text" placeholder="Title"
                            class="w-full px-2 py-1 text-sm rounded border border-gray-300 dark:border-gray-600 bg-transparent">
                        <input id="ql-url" type="text" placeholder="https://…"
                            class="w-full px-2 py-1 text-sm rounded border border-gray-300 dark:border-gray-600 bg-transparent">
                        <button id="ql-add"
                            class="w-full px-2 py-1 text-sm rounded bg-indigo-600 text-white hover:bg-indigo-700">Add link</button>
                    </div>
                </div>
            </div>

            <!-- 1-Click Backup -->
            <a href="api.php?action=backup&format=sql"
               class="px-3 py-1.5 rounded-md text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700"
               title="Download a .sql backup">💾 Backup</a>

            <!-- Dark mode toggle -->
            <button id="theme-toggle" type="button"
                class="px-3 py-1.5 rounded-md text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700"
                title="Toggle dark mode">
                <span class="dark:hidden">🌙</span>
                <span class="hidden dark:inline">☀️</span>
            </button>
        </div>
    </div>
</nav>

<!-- Toast container for AJAX feedback -->
<div id="toast" class="fixed bottom-4 right-4 z-50 space-y-2"></div>

<main class="max-w-7xl mx-auto px-4 py-6">
