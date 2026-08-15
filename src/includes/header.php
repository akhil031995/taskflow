<?php
/**
 * App shell: dark sidebar + topbar layout shared by every screen.
 *
 * Pages set (before requiring this file):
 *   $pageTitle        - browser title
 *   $activeNav        - one of: dashboard | projects | settings | logs
 *   $activeProjectId  - (optional) highlights a project in the sidebar
 */
require_once __DIR__ . '/functions.php';

$pageTitle       = $pageTitle ?? 'TaskFlow AI';
$activeNav       = $activeNav ?? '';
$activeProjectId = $activeProjectId ?? 0;
$quickLinks      = get_quick_links();
$sidebarProjects = get_projects();

/** Sidebar nav-item classes for active vs idle. */
function nav_cls(bool $active): string
{
    return $active
        ? 'bg-indigo-600/20 text-white ring-1 ring-inset ring-indigo-500/40'
        : 'text-slate-400 hover:text-white hover:bg-white/5';
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · TaskFlow AI</title>
    <script>
        // Default to dark (the product's primary look); honor a saved choice.
        if (localStorage.getItem('taskflow-theme') !== 'light') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <style>
        /* Slim scrollbars that suit the dark theme. */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: rgba(148,163,184,.35); border-radius: 8px; }

        /* Compact board view: show only the card title + status capsule. */
        #view-board.compact-mode .task-card .card-full-content { display: none; }
        #view-board.compact-mode .task-card .compact-row { display: flex; }
    </style>
</head>
<body class="h-full bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
<div class="flex h-full relative">

    <!-- Mobile sidebar backdrop -->
    <div id="sidebar-backdrop" class="hidden fixed inset-0 z-30 bg-black/50 md:hidden"></div>

    <!-- ======================= SIDEBAR ======================= -->
    <aside id="sidebar"
        class="w-60 shrink-0 bg-slate-900 text-slate-300 flex flex-col border-r border-slate-800
               fixed inset-y-0 left-0 z-40 -translate-x-full transition-transform duration-200
               md:static md:translate-x-0">
        <!-- Brand -->
        <div class="flex items-center justify-between px-5 h-16 border-b border-slate-800">
            <a href="index.php" class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-white font-bold">TF</span>
                <span class="font-semibold text-white text-lg truncate">TaskFlow <span class="text-indigo-400">AI</span></span>
            </a>
            <button id="sidebar-close" class="md:hidden text-slate-400 hover:text-white p-1" aria-label="Close menu">✕</button>
        </div>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto p-3 space-y-1 text-sm">
            <a href="index.php" class="flex items-center gap-3 px-3 py-2 rounded-lg <?= nav_cls($activeNav === 'dashboard') ?>">
                <span>🏠</span> Dashboard
            </a>

            <!-- Projects (expandable) -->
            <div>
                <button id="nav-projects-toggle"
                    class="w-full flex items-center justify-between gap-3 px-3 py-2 rounded-lg <?= nav_cls($activeNav === 'projects') ?>">
                    <span class="flex items-center gap-3"><span>📁</span> Projects</span>
                    <span id="nav-projects-caret" class="text-xs transition-transform <?= $activeNav === 'projects' ? 'rotate-90' : '' ?>">▸</span>
                </button>
                <div id="nav-projects-list" class="<?= $activeNav === 'projects' ? '' : 'hidden' ?> mt-1 ml-3 pl-3 border-l border-slate-800 space-y-0.5">
                    <?php foreach ($sidebarProjects as $sp): ?>
                        <a href="project.php?id=<?= (int) $sp['id'] ?>"
                           class="flex items-center gap-2 px-3 py-1.5 rounded-md text-xs <?= (int) $sp['id'] === (int) $activeProjectId ? 'text-white bg-white/5' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                            <span class="h-2 w-2 rounded-full shrink-0" style="background: <?= e($sp['color']) ?>"></span>
                            <span class="truncate"><?= e($sp['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($sidebarProjects)): ?>
                        <p class="px-3 py-1.5 text-xs text-slate-500">No projects yet</p>
                    <?php endif; ?>
                </div>
            </div>

            <a href="settings.php" class="flex items-center gap-3 px-3 py-2 rounded-lg <?= nav_cls($activeNav === 'settings') ?>">
                <span>⚙️</span> Settings
            </a>
            <a href="api.php?action=backup&format=sql" class="flex items-center gap-3 px-3 py-2 rounded-lg <?= nav_cls(false) ?>">
                <span>💾</span> Backup
            </a>
            <a href="logs.php" class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg <?= nav_cls($activeNav === 'logs') ?>">
                <span class="flex items-center gap-3"><span>📊</span> AI Agent Logs</span>
                <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_8px] shadow-emerald-400/70"></span>
            </a>
        </nav>

        <!-- Active Code Sessions (running / paused only), populated by session_monitor.js -->
        <div id="sessions-panel" class="border-t border-slate-800">
            <button id="sessions-toggle" class="w-full flex items-center justify-between px-4 py-2.5 hover:bg-white/5">
                <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
                    </span>
                    Active Sessions <span id="sessions-count" class="text-slate-500">0</span>
                </span>
                <span id="sessions-caret" class="text-xs">▾</span>
            </button>
            <ul id="sessions-list" class="max-h-56 overflow-y-auto px-2 pb-2 space-y-1 text-sm">
                <li class="px-2 py-2 text-[11px] text-slate-500">No active sessions</li>
            </ul>
        </div>
    </aside>

    <!-- ==================== MAIN COLUMN ==================== -->
    <div class="flex-1 flex flex-col min-w-0">

        <!-- Topbar -->
        <header class="h-16 shrink-0 flex items-center gap-2 sm:gap-3 px-3 sm:px-5 bg-white/70 dark:bg-slate-900/70 backdrop-blur border-b border-slate-200 dark:border-slate-800">
            <button id="sidebar-open" type="button" aria-label="Open menu"
                class="md:hidden shrink-0 px-2.5 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                ☰
            </button>

            <div class="relative flex-1 min-w-0 max-w-md">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">🔍</span>
                <input id="global-search" type="search" placeholder="Search…"
                    class="w-full pl-9 pr-3 py-2 rounded-lg text-sm bg-slate-100 dark:bg-slate-800 border border-transparent focus:border-indigo-500 focus:outline-none">
            </div>

            <div class="ml-auto flex items-center gap-1 sm:gap-1.5">
                <button id="theme-toggle" type="button"
                    class="px-2.5 sm:px-3 py-1.5 rounded-lg text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-1.5">
                    <span class="dark:hidden"><span aria-hidden="true">🌙</span><span class="hidden sm:inline"> Dark</span></span>
                    <span class="hidden dark:inline"><span aria-hidden="true">☀️</span><span class="hidden sm:inline"> Light</span></span>
                </button>

                <!-- Quick links -->
                <div class="relative" id="ql-wrapper">
                    <button id="ql-toggle" type="button"
                        class="px-2.5 sm:px-3 py-1.5 rounded-lg text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center gap-1.5">
                        🔗 <span class="hidden sm:inline">Quick links</span>
                    </button>
                    <div id="ql-menu" class="hidden absolute right-0 mt-2 w-72 max-w-[85vw] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl p-2 z-50">
                        <ul id="ql-list" class="max-h-64 overflow-auto text-sm">
                            <?php foreach ($quickLinks as $link): ?>
                                <li class="flex items-center justify-between gap-2 group px-2 py-1.5 rounded hover:bg-slate-100 dark:hover:bg-slate-700" data-id="<?= (int) $link['id'] ?>">
                                    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" class="truncate text-indigo-600 dark:text-indigo-400 hover:underline"><?= e($link['title']) ?></a>
                                    <button class="ql-del opacity-0 group-hover:opacity-100 text-slate-400 hover:text-red-500" title="Delete">✕</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="border-t border-slate-200 dark:border-slate-700 mt-2 pt-2 space-y-1">
                            <input id="ql-title" type="text" placeholder="Title" class="w-full px-2 py-1 text-sm rounded border border-slate-300 dark:border-slate-600 bg-transparent">
                            <input id="ql-url" type="text" placeholder="https://…" class="w-full px-2 py-1 text-sm rounded border border-slate-300 dark:border-slate-600 bg-transparent">
                            <button id="ql-add" class="w-full px-2 py-1 text-sm rounded bg-indigo-600 text-white hover:bg-indigo-700">Add link</button>
                        </div>
                    </div>
                </div>

                <button class="px-2.5 py-1.5 rounded-lg text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 relative" title="Notifications">
                    🔔<span class="absolute top-1 right-1.5 h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                </button>

                <div class="flex items-center gap-2 pl-2 ml-1 border-l border-slate-200 dark:border-slate-700">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-white text-xs font-semibold">U</span>
                    <div class="leading-tight hidden sm:block">
                        <p class="text-sm font-medium">User</p>
                        <p class="text-[11px] text-slate-500">Chief Developer</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page content -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6">
            <div id="toast" class="fixed bottom-4 right-4 z-[60] space-y-2"></div>
