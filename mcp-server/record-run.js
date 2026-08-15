#!/usr/bin/env node
/**
 * Parse one run's `claude --output-format stream-json` log (written by
 * run-agent.sh) for the terminal `result` event's token usage/cost, and
 * persist it to the `runs` table (see migrations 010_runs_table.sql /
 * 011_run_tagging.sql).
 *
 * Normal path: run-agent.sh already created the row via start-run.js before
 * Claude launched (so mcp_invocations/ai_session_logs could be tagged with
 * its id all session long) - this UPDATEs that same row by run_id with the
 * finish-time fields. Fallback path: if no run_id is available (e.g.
 * record-run.js invoked manually, or a start-run.js failure), INSERT a new
 * row instead so cost capture still degrades gracefully rather than being
 * lost outright.
 *
 * Best-effort by design: run-agent.sh calls this AFTER the session already
 * finished, so a parse/DB failure here must never fail that iteration - it
 * only means one run's cost goes unrecorded.
 *
 * Usage: node record-run.js <run_id|-> <task_id|-> <project_id|-> <run_log_path> <exit_code> <started_at_iso>
 */
import fs from 'node:fs';
import readline from 'node:readline';
import { pool } from './db.js';

async function main() {
  const [, , runIdArg, taskIdArg, projectIdArg, runLogPath, exitCodeArg, startedAtArg] = process.argv;
  const runId = runIdArg && runIdArg !== '-' ? Number(runIdArg) : null;
  const taskId = taskIdArg && taskIdArg !== '-' ? Number(taskIdArg) : null;
  const projectId = projectIdArg && projectIdArg !== '-' ? Number(projectIdArg) : null;
  const exitCode = exitCodeArg !== undefined && exitCodeArg !== '' ? Number(exitCodeArg) : null;
  const startedAt = startedAtArg ? new Date(startedAtArg) : new Date();

  if (!runLogPath || !fs.existsSync(runLogPath)) {
    console.error(`[record-run] log file not found: ${runLogPath}; skipping.`);
    return;
  }

  let model = null;
  let result = null; // the stream's terminal `result` event (last one wins)

  const rl = readline.createInterface({
    input: fs.createReadStream(runLogPath),
    terminal: false,
  });

  for await (const raw of rl) {
    const line = raw.trim();
    if (!line) continue;
    let ev;
    try {
      ev = JSON.parse(line);
    } catch {
      continue; // stray non-JSON line (e.g. stderr merged into the tee) - skip
    }
    if (ev.type === 'system' && ev.subtype === 'init' && ev.model) {
      model = ev.model;
    } else if (ev.type === 'result') {
      result = ev;
    }
  }

  const usage = result?.usage || {};
  // 75 is run-agent.sh's own rate-limit exit code (see its detection logic);
  // it never comes from Claude itself, so it's checked before is_error.
  const isError = result ? (result.is_error ? 1 : 0) : (exitCode !== 0 && exitCode !== null ? 1 : 0);
  const outcome = exitCode === 75 ? 'rate_limited' : (isError ? 'error' : 'success');
  const values = [
    exitCode,
    isError,
    outcome,
    result?.num_turns ?? null,
    result?.duration_ms ?? null,
    result?.duration_api_ms ?? null,
    model,
    usage.input_tokens ?? null,
    usage.output_tokens ?? null,
    usage.cache_creation_input_tokens ?? null,
    usage.cache_read_input_tokens ?? null,
    result?.total_cost_usd ?? null,
  ];

  if (runId) {
    const [res] = await pool.query(
      `UPDATE runs
          SET finished_at = NOW(), exit_code = ?, is_error = ?, outcome = ?,
              num_turns = ?, duration_ms = ?, duration_api_ms = ?, model = ?,
              input_tokens = ?, output_tokens = ?, cache_creation_tokens = ?,
              cache_read_tokens = ?, total_cost_usd = ?
        WHERE id = ?`,
      [...values, runId]
    );
    if (res.affectedRows === 0) {
      console.error(`[record-run] no runs row with id ${runId}; inserting a new one instead.`);
      await pool.query(
        `INSERT INTO runs
           (task_id, project_id, started_at, finished_at, exit_code, is_error, outcome,
            num_turns, duration_ms, duration_api_ms, model,
            input_tokens, output_tokens, cache_creation_tokens, cache_read_tokens,
            total_cost_usd)
         VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [taskId, projectId, startedAt, ...values]
      );
    }
  } else {
    await pool.query(
      `INSERT INTO runs
         (task_id, project_id, started_at, finished_at, exit_code, is_error, outcome,
          num_turns, duration_ms, duration_api_ms, model,
          input_tokens, output_tokens, cache_creation_tokens, cache_read_tokens,
          total_cost_usd)
       VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [taskId, projectId, startedAt, ...values]
    );
  }

  console.error(
    result
      ? `[record-run] recorded run ${runId ?? '(new)'} for task ${taskId ?? '-'}: $${(result.total_cost_usd ?? 0).toFixed(4)}, ` +
        `${usage.input_tokens ?? 0} in / ${usage.output_tokens ?? 0} out tokens, outcome=${outcome}`
      : `[record-run] no result event found in ${runLogPath}; recorded run ${runId ?? '(new)'} with null usage/cost, outcome=${outcome}.`
  );
}

main()
  .catch((e) => console.error('[record-run] error:', e.message))
  .finally(() => pool.end());
