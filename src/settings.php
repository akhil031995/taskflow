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

    <!-- Model routing -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1 flex items-center gap-2">🧭 Model Routing</h2>
        <p class="text-xs text-slate-500 mb-4">
            run-agent.sh picks a model per ticket before launching Claude, so small/low-priority
            tickets can run on a cheaper model while the top model is reserved for complex work.
            A task-type override (if it matches) wins over the priority mapping; the default model
            is the fallback when neither matches.
        </p>

        <div class="flex items-center justify-between mb-4">
            <span class="text-sm font-medium">Enabled</span>
            <select data-setting="model_routing_enabled" class="px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900 text-xs">
                <option value="1" <?= $val('model_routing_enabled', '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                <option value="0" <?= $val('model_routing_enabled', '1') === '0' ? 'selected' : '' ?>>Disabled (always use the Claude CLI's default model)</option>
            </select>
        </div>

        <div class="grid sm:grid-cols-2 gap-4 mb-4">
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Priority 1 (High) model</span>
                <input data-setting="model_routing_priority_1" type="text" placeholder="claude-opus-5" value="<?= e($val('model_routing_priority_1', 'claude-opus-5')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Priority 2 (Medium) model</span>
                <input data-setting="model_routing_priority_2" type="text" placeholder="claude-sonnet-5" value="<?= e($val('model_routing_priority_2', 'claude-sonnet-5')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Priority 3 (Low) model</span>
                <input data-setting="model_routing_priority_3" type="text" placeholder="claude-haiku-4-5-20251001" value="<?= e($val('model_routing_priority_3', 'claude-haiku-4-5-20251001')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
            <label class="text-sm">
                <span class="text-slate-500 dark:text-slate-400">Default / fallback model</span>
                <input data-setting="model_routing_default_model" type="text" placeholder="claude-sonnet-5" value="<?= e($val('model_routing_default_model', 'claude-sonnet-5')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
            </label>
        </div>

        <label class="text-sm block">
            <span class="text-slate-500 dark:text-slate-400">Task-type overrides (JSON, optional)</span>
            <textarea data-setting="model_routing_task_type_overrides" rows="3" placeholder='{"sub-task": "claude-haiku-4-5-20251001"}'
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs leading-relaxed"><?= e($val('model_routing_task_type_overrides')) ?></textarea>
            <span class="text-[11px] text-slate-500">Keys are <code>feature</code> / <code>bug</code> / <code>tech-debt</code> / <code>sub-task</code>. Takes precedence over the priority mapping above.</span>
        </label>
    </section>

    <!-- Notifications -->
    <section class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 mb-5">
        <h2 class="font-semibold mb-1 flex items-center gap-2">🔔 Notifications</h2>
        <p class="text-xs text-slate-500 mb-4">
            Fired when a ticket reaches <code>completed</code>, <code>blocked</code>, or <code>rate-limited-paused</code>,
            so a human is pulled in when the agent needs one. Each channel is independent and can be switched off; a
            delivery failure on one channel never blocks the others or the ticket update itself.
        </p>

        <div class="mb-5 pb-5 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium">Webhook</h3>
                <select data-setting="notify_webhook_enabled" class="px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900 text-xs">
                    <option value="1" <?= $val('notify_webhook_enabled', '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= $val('notify_webhook_enabled', '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <label class="text-sm block">
                <span class="text-slate-500 dark:text-slate-400">Webhook URL</span>
                <input data-setting="notify_webhook_url" type="text" placeholder="https://example.com/hooks/taskflow" value="<?= e($val('notify_webhook_url')) ?>"
                    class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                <span class="text-[11px] text-slate-500">Receives a JSON POST: <code>{ event, ticket: { id, key, title, status, project }, link, reason, timestamp }</code>.</span>
            </label>
        </div>

        <div class="mb-5 pb-5 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium">Telegram</h3>
                <select data-setting="notify_telegram_enabled" class="px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900 text-xs">
                    <option value="1" <?= $val('notify_telegram_enabled', '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= $val('notify_telegram_enabled', '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">Bot token</span>
                    <input data-setting="notify_telegram_bot_token" type="password" value="<?= e($val('notify_telegram_bot_token')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">Chat ID</span>
                    <input data-setting="notify_telegram_chat_id" type="text" value="<?= e($val('notify_telegram_chat_id')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <button id="telegram-test-btn" type="button" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800">Test connection</button>
                <span id="telegram-test-result" class="text-xs text-slate-500"></span>
            </div>
        </div>

        <div class="mb-5 pb-5 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium">Email (SMTP)</h3>
                <select data-setting="notify_email_enabled" class="px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900 text-xs">
                    <option value="1" <?= $val('notify_email_enabled', '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= $val('notify_email_enabled', '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">SMTP host</span>
                    <input data-setting="notify_email_smtp_host" type="text" value="<?= e($val('notify_email_smtp_host')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">SMTP port</span>
                    <input data-setting="notify_email_smtp_port" type="number" min="1" value="<?= e($val('notify_email_smtp_port', '587')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">SMTP user</span>
                    <input data-setting="notify_email_smtp_user" type="text" value="<?= e($val('notify_email_smtp_user')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">SMTP password</span>
                    <input data-setting="notify_email_smtp_pass" type="password" value="<?= e($val('notify_email_smtp_pass')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">Use TLS (implicit)</span>
                    <select data-setting="notify_email_smtp_secure" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent dark:bg-slate-900">
                        <option value="0" <?= $val('notify_email_smtp_secure', '0') === '0' ? 'selected' : '' ?>>No (STARTTLS on 587)</option>
                        <option value="1" <?= $val('notify_email_smtp_secure', '0') === '1' ? 'selected' : '' ?>>Yes (port 465)</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="text-slate-500 dark:text-slate-400">From address</span>
                    <input data-setting="notify_email_from" type="text" placeholder="taskflow@example.com" value="<?= e($val('notify_email_from')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
                <label class="text-sm sm:col-span-2">
                    <span class="text-slate-500 dark:text-slate-400">Notify address(es) (comma-separated)</span>
                    <input data-setting="notify_email_to" type="text" value="<?= e($val('notify_email_to')) ?>"
                        class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
                </label>
            </div>
        </div>

        <label class="text-sm block">
            <span class="text-slate-500 dark:text-slate-400">TaskFlow URL (used to build the ticket link in notifications)</span>
            <input data-setting="notify_app_base_url" type="text" placeholder="https://taskflowdev.littlebitofeverything.in" value="<?= e($val('notify_app_base_url')) ?>"
                class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-transparent font-mono text-xs">
        </label>
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

document.getElementById('telegram-test-btn').addEventListener('click', async () => {
    const btn = document.getElementById('telegram-test-btn');
    const result = document.getElementById('telegram-test-result');
    const botToken = document.querySelector('[data-setting="notify_telegram_bot_token"]').value.trim();
    const chatId = document.querySelector('[data-setting="notify_telegram_chat_id"]').value.trim();
    if (!botToken || !chatId) {
        toast('Enter a bot token and chat ID first', 'error');
        return;
    }
    btn.disabled = true;
    result.textContent = 'Sending…';
    result.className = 'text-xs text-slate-500';
    try {
        await api('settings.test.telegram', { bot_token: botToken, chat_id: chatId });
        result.textContent = 'Sent ✓';
        result.className = 'text-xs text-emerald-500';
        toast('Test message sent to Telegram');
    } catch (err) {
        result.textContent = 'Failed';
        result.className = 'text-xs text-red-500';
        toast(err.message, 'error');
    } finally {
        btn.disabled = false;
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
