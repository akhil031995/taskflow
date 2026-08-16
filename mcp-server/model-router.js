// Model routing: picks which Claude model run-agent.sh should launch for a
// claimed ticket, based on its priority (1 High / 2 Medium / 3 Low) and
// task_type (feature / bug / tech-debt / sub-task). Lets cheap, low-stakes
// tickets (e.g. a Low-priority sub-task) run on a smaller model while
// reserving the top model for High-priority/complex work - see TF-29.
//
// Settings live in the same `settings` key/value table as the rest of the
// control plane (see src/settings.php's Model Routing section + api.php's
// settings_save allow-list):
//   model_routing_enabled           - '1'/'0', routing is a no-op when off
//   model_routing_default_model     - fallback when nothing else matches
//   model_routing_priority_1/2/3    - model for that priority tier
//   model_routing_task_type_overrides - JSON object, task_type -> model,
//                                       takes precedence over the priority tier
import { pool } from './db.js';

function truthy(v) {
  return v === '1' || v === 1 || v === true || v === 'true';
}

async function loadSettings() {
  const [rows] = await pool.query(
    "SELECT setting_key, value FROM settings WHERE setting_key LIKE 'model\\_routing\\_%'"
  );
  const s = {};
  for (const r of rows) s[r.setting_key] = r.value;
  return s;
}

function parseOverrides(raw) {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    console.error('[model-router] model_routing_task_type_overrides is not valid JSON; ignoring it.');
    return {};
  }
}

/**
 * Resolve which model to launch for a ticket. Returns { model, reason } where
 * `model` is null when routing is disabled or nothing matches (caller should
 * fall back to the Claude CLI's own default, i.e. omit --model).
 */
export function resolveModel(ticket, settings) {
  if (!truthy(settings.model_routing_enabled ?? '1')) {
    return { model: null, reason: 'routing disabled' };
  }

  const overrides = parseOverrides(settings.model_routing_task_type_overrides);
  const taskType = ticket.task_type;
  if (taskType && overrides[taskType]) {
    return { model: overrides[taskType], reason: `task_type=${taskType} override` };
  }

  const priorityKey = `model_routing_priority_${ticket.priority}`;
  if (settings[priorityKey]) {
    return { model: settings[priorityKey], reason: `priority=${ticket.priority}` };
  }

  if (settings.model_routing_default_model) {
    return { model: settings.model_routing_default_model, reason: 'default fallback' };
  }

  return { model: null, reason: 'no mapping and no default set' };
}

export async function resolveModelForTicket(ticket) {
  const settings = await loadSettings();
  return resolveModel(ticket, settings);
}
