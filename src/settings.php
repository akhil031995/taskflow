<?php
/**
 * MCP Settings & System Prompts.
 * Edit MCP connection settings, the agent system prompt, and operating rules.
 * All fields persist to the `settings` table via api.php?action=settings.save.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'MCP Settings';
$activeNav = 'settings';
$s         = get_all_settings();

/** Small helper to read a setting with a fallback. */
$val = fn (string $k, string $d = '') => $s[$k] ?? $d;

// The four tools the MCP server exposes (for the reference panel).
$mcpTools = [
    'get_highest_priority_ticket' => 'Claim + lock the top pending ticket',
    'update_ticket_status'        => 'Set completed / blocked / rate-limited-paused',
    'add_ticket_comment'          => 'Append a timestamped implementation note',
    'create_ticket'               => 'File an AI-authored sub-task / tech-debt ticket',
];

require __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold">MCP Settings & System Prompts</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Configure the autonomous agent control plane.</p>
        </div>
        <button id="settings-save" class="px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700">Save changes</button>
    </div>

    <!-- Connection settings -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-4 flex items-center gap-2">🔌 MCP Connection</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Poll interval (minutes)</span>
                <input data-setting="mcp_poll_interval_minutes" type="number" min="1" value="<?= e($val('mcp_poll_interval_minutes', '30')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent">
            </label>
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Enabled</span>
                <select data-setting="mcp_enabled" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900">
                    <option value="1" <?= $val('mcp_enabled', '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= $val('mcp_enabled', '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </label>
            <label class="text-sm sm:col-span-2">
                <span class="text-slate-500 dark:text-slate-400">MCP server launch command</span>
                <input data-setting="mcp_server_command" type="text" value="<?= e($val('mcp_server_command', 'node /var/www/mcp-server/index.js')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm sm:col-span-2">
                <span class="text-slate-500 dark:text-slate-400">Host projects root</span>
                <input data-setting="host_projects_root" type="text" value="<?= e($val('host_projects_root', '/home/akhil/development')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                <span class="text-[11px] text-slate-500">Real host path mounted at <code>/workspaces</code>; the folder picker returns paths under this.</span>
            </label>
        </div>
        <p class="text-xs text-slate-500 mt-3">Database connection is read from the MCP server's own <code>.env</code> (shares the <code>dev_taskflow</code> MySQL).</p>
    </section>

    <!-- Tools reference -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-4 flex items-center gap-2">🧰 Exposed Tools</h2>
        <ul class="space-y-2 text-sm">
            <?php foreach ($mcpTools as $name => $desc): ?>
                <li class="flex items-center justify-between gap-3 py-1.5 border-b border-slate-100 dark:border-slate-800 last:border-0">
                    <code class="text-indigo-500 dark:text-indigo-400"><?= e($name) ?></code>
                    <span class="text-slate-500 dark:text-slate-400 text-right"><?= e($desc) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- System prompt -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1 flex items-center gap-2">🤖 Agent System Prompt</h2>
        <p class="text-xs text-slate-500 mb-3">Passed into each headless Claude Code session (mirrors <code>AGENT_PROMPT.md</code>).</p>
        <textarea data-setting="agent_system_prompt" rows="8"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"><?= e($val('agent_system_prompt')) ?></textarea>
    </section>

    <!-- Operating rules -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1 flex items-center gap-2">📋 Operating Rules</h2>
        <p class="text-xs text-slate-500 mb-3">Cached navigation / git / decomposition rules (mirrors <code>CLAUDE.md</code>).</p>
        <textarea data-setting="agent_operating_rules" rows="6"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"><?= e($val('agent_operating_rules')) ?></textarea>
    </section>

    <!-- Org-wide standards baseline -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1 flex items-center gap-2">🌐 Org Standards Baseline</h2>
        <p class="text-xs text-slate-500 mb-3">
            Applies to every project. Each project can layer its own overrides under its
            <strong>Standards</strong> tab &mdash; the two are merged into the effective standards
            that get resolved into that project's <code>CLAUDE.md</code> when a ticket is claimed.
        </p>
        <textarea data-setting="org_standards_baseline" rows="6"
            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"><?= e($val('org_standards_baseline')) ?></textarea>
    </section>
</div>

<script>
document.getElementById('settings-save').addEventListener('click', async () => {
    const settings = {};
    document.querySelectorAll('[data-setting]').forEach((el) => {
        settings[el.dataset.setting] = el.value;
    });
    try {
        await api('settings.save', { settings });
        toast('Settings saved');
    } catch (err) {
        toast(err.message, 'error');
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
