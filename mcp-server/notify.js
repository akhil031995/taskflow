// Best-effort outbound notifications (generic webhook, Telegram, email) fired
// on every ticket lifecycle transition a human should know about without
// polling the board: created, in-progress (claimed/resumed), completed,
// blocked, rate-limited-paused, and error (session died without reporting a
// status). Settings live in the same `settings` key/value table as the rest
// of the control plane (see src/settings.php's Notifications section +
// api.php's settings_save allow-list), each channel independently toggled.
//
// Every channel is wrapped so a delivery failure only logs to stderr - it must
// never surface as a tool error or block the status update that triggered it
// (update_ticket_status, the DoD-gate auto-block path, finalizeClaim,
// create_ticket, reconcile.js, and markTicketBlocked all call
// notifyTicketEvent and ignore its outcome).
import nodemailer from 'nodemailer';
import { pool } from './db.js';

const DELIVERY_TIMEOUT_MS = 10_000;

const EVENT_LABELS = {
  created: 'Created',
  'in-progress': 'In Progress',
  completed: 'Completed',
  blocked: 'Blocked',
  'rate-limited-paused': 'Rate-limited (paused)',
  error: 'Error',
};

const EVENT_ICONS = {
  created: '\u{1F195}',
  'in-progress': '\u{25B6}\u{FE0F}',
  completed: '\u{2705}',
  blocked: '\u{26D4}',
  'rate-limited-paused': '\u{23F8}\u{FE0F}',
  error: '\u{26A0}\u{FE0F}',
};

function truthy(v) {
  return v === '1' || v === 1 || v === true || v === 'true';
}

function ticketKey(id) {
  return 'TF-' + String(id).padStart(3, '0');
}

function fmtInt(n) {
  return Number(n).toLocaleString('en-US');
}

/**
 * Render a task's cumulative token/cost usage (see the SUM(...) query in
 * record-run.js's sendCompletionNotification) as a short block of lines, or
 * [] when nothing usable was passed - most events have no usage attached, and
 * a run whose stream-json log never produced a `result` event has all-null
 * fields even when it does.
 */
function formatUsageLines(usage) {
  if (!usage) return [];
  const lines = [];
  const hasIn = usage.input_tokens !== null && usage.input_tokens !== undefined;
  const hasOut = usage.output_tokens !== null && usage.output_tokens !== undefined;
  if (hasIn || hasOut) {
    lines.push(`Tokens: ${fmtInt(usage.input_tokens || 0)} in / ${fmtInt(usage.output_tokens || 0)} out`);
  }
  const cacheCreate = Number(usage.cache_creation_tokens || 0);
  const cacheRead = Number(usage.cache_read_tokens || 0);
  if (cacheCreate || cacheRead) {
    lines.push(`Cache: ${fmtInt(cacheCreate)} created / ${fmtInt(cacheRead)} read`);
  }
  if (usage.total_cost_usd !== null && usage.total_cost_usd !== undefined) {
    lines.push(`Cost: $${Number(usage.total_cost_usd).toFixed(4)}`);
  }
  if (lines.length && usage.runs && Number(usage.runs) > 1) {
    lines.push(`(across ${usage.runs} runs)`);
  }
  return lines;
}

async function loadSettings() {
  const [rows] = await pool.query(
    "SELECT setting_key, value FROM settings WHERE setting_key LIKE 'notify\\_%'"
  );
  const s = {};
  for (const r of rows) s[r.setting_key] = r.value;
  return s;
}

async function loadTicket(taskId) {
  const [rows] = await pool.query(
    `SELECT t.id, t.title, t.status, t.ai_execution_status,
            p.id AS project_id, p.name AS project_name
       FROM tasks t JOIN projects p ON p.id = t.project_id
      WHERE t.id = ?`,
    [taskId]
  );
  return rows[0] || null;
}

function buildPayload(ticket, event, settings, reason, usage) {
  const base = (settings.notify_app_base_url || '').trim().replace(/\/+$/, '');
  const link = base ? `${base}/project.php?id=${ticket.project_id}` : null;
  return {
    event,
    ticket: {
      id: ticket.id,
      key: ticketKey(ticket.id),
      title: ticket.title,
      status: ticket.status,
      ai_execution_status: ticket.ai_execution_status,
      project: ticket.project_name,
    },
    link,
    reason: reason || null,
    usage: usage || null,
    timestamp: new Date().toISOString(),
  };
}

function messageText(ticket, event, payload) {
  const icon = EVENT_ICONS[event];
  const lines = [
    `${icon ? icon + ' ' : ''}TaskFlow ${ticketKey(ticket.id)} — ${EVENT_LABELS[event] || event}`,
    ticket.title,
    `Project: ${ticket.project_name}`,
  ];
  if (payload.reason) {
    lines.push('');
    lines.push(`Reason: ${payload.reason}`);
  }
  const usageLines = formatUsageLines(payload.usage);
  if (usageLines.length) {
    lines.push('');
    lines.push(...usageLines);
  }
  if (payload.link) {
    lines.push('');
    lines.push(payload.link);
  }
  return lines.join('\n');
}

async function sendWebhook(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(DELIVERY_TIMEOUT_MS),
  });
  if (!res.ok) throw new Error(`webhook responded ${res.status}`);
}

async function sendTelegram(botToken, chatId, text) {
  const res = await fetch(`https://api.telegram.org/bot${botToken}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ chat_id: chatId, text }),
    signal: AbortSignal.timeout(DELIVERY_TIMEOUT_MS),
  });
  if (!res.ok) throw new Error(`Telegram API responded ${res.status}`);
}

async function sendEmail(settings, subject, text) {
  const transporter = nodemailer.createTransport({
    host: settings.notify_email_smtp_host,
    port: Number(settings.notify_email_smtp_port || 587),
    secure: truthy(settings.notify_email_smtp_secure),
    auth: settings.notify_email_smtp_user
      ? { user: settings.notify_email_smtp_user, pass: settings.notify_email_smtp_pass }
      : undefined,
    connectionTimeout: DELIVERY_TIMEOUT_MS,
  });
  await transporter.sendMail({
    from: settings.notify_email_from || settings.notify_email_smtp_user,
    to: settings.notify_email_to,
    subject,
    text,
  });
}

/**
 * Fire every enabled notification channel for a ticket lifecycle event.
 * `reason` is an optional short human-readable cause (e.g. a DoD gate
 * failure or block reason) folded into the message/payload. `usage` is an
 * optional { input_tokens, output_tokens, cache_creation_tokens,
 * cache_read_tokens, total_cost_usd, runs? } breakdown - currently only
 * passed for the 'completed' event, by record-run.js, once a run's actual
 * token usage is known (see that file's header comment for why this can't
 * be known at the time update_ticket_status itself fires the event).
 */
export async function notifyTicketEvent(taskId, event, reason = null, usage = null) {
  try {
    const [settings, ticket] = await Promise.all([loadSettings(), loadTicket(taskId)]);
    if (!ticket) return;

    const payload = buildPayload(ticket, event, settings, reason, usage);
    const text = messageText(ticket, event, payload);
    const subject = `${EVENT_ICONS[event] ? EVENT_ICONS[event] + ' ' : ''}TaskFlow ${ticketKey(ticket.id)} ${EVENT_LABELS[event] || event}`;

    const deliveries = [];
    if (truthy(settings.notify_webhook_enabled) && settings.notify_webhook_url) {
      deliveries.push(
        sendWebhook(settings.notify_webhook_url, payload).catch((err) =>
          console.error(`[notify] webhook delivery failed for TF-${taskId}:`, err.message)
        )
      );
    }
    if (
      truthy(settings.notify_telegram_enabled) &&
      settings.notify_telegram_bot_token &&
      settings.notify_telegram_chat_id
    ) {
      deliveries.push(
        sendTelegram(settings.notify_telegram_bot_token, settings.notify_telegram_chat_id, text).catch(
          (err) => console.error(`[notify] telegram delivery failed for TF-${taskId}:`, err.message)
        )
      );
    }
    if (truthy(settings.notify_email_enabled) && settings.notify_email_to && settings.notify_email_smtp_host) {
      deliveries.push(
        sendEmail(settings, subject, text).catch((err) =>
          console.error(`[notify] email delivery failed for TF-${taskId}:`, err.message)
        )
      );
    }
    await Promise.all(deliveries);
  } catch (err) {
    // Settings/ticket lookup itself failed - still must not affect the caller.
    console.error(`[notify] failed to dispatch notifications for TF-${taskId}:`, err.message);
  }
}
