// Per-project Definition-of-Done gates: optional lint/test/build commands run
// in the ticket's project_folder before update_ticket_status is allowed to
// mark a ticket completed, plus an optional per-project override of
// ACCEPTANCE_CRITERIA_TEMPLATE for newly created tickets.
//
// Mirrors get_project_dod_gates()/effective_acceptance_criteria() in
// src/includes/functions.php - kept as a standalone module (not shared code,
// since PHP and Node don't share a runtime) so both sides agree on shape.
import { spawnSync } from 'node:child_process';
import { ACCEPTANCE_CRITERIA_TEMPLATE } from './db.js';

// Generous per-gate ceiling so a slow test suite doesn't hang the MCP call
// forever, while still giving real lint/test/build commands room to finish.
const GATE_TIMEOUT_MS = 5 * 60 * 1000;

/** A project's configured gate commands; each is null when not configured (gate skipped). */
export async function getProjectDodGates(pool, projectId) {
  const [[row]] = await pool.query(
    `SELECT lint_cmd, test_cmd, build_cmd, criteria_md FROM project_dod_gates WHERE project_id = ?`,
    [projectId]
  );
  return {
    lint_cmd: row?.lint_cmd || null,
    test_cmd: row?.test_cmd || null,
    build_cmd: row?.build_cmd || null,
    criteria_md: row?.criteria_md || null,
  };
}

/** The acceptance-criteria template to stamp on a new AI-created ticket for this project. */
export async function getEffectiveAcceptanceCriteria(pool, projectId) {
  const gates = await getProjectDodGates(pool, projectId);
  return gates.criteria_md || ACCEPTANCE_CRITERIA_TEMPLATE;
}

/**
 * Run a project's configured lint/test/build gates, in that order, in
 * projectFolder. Stops at the first failing gate (fail-fast) rather than
 * running the rest. A project with no commands configured passes trivially.
 *
 * Returns { passed, report } - report is a plain-text transcript (command,
 * exit code, captured stdout+stderr per gate that ran) suitable for
 * attaching to the ticket as a comment when gates fail.
 */
export function runDodGates(projectFolder, gates) {
  const steps = [
    ['lint', gates.lint_cmd],
    ['test', gates.test_cmd],
    ['build', gates.build_cmd],
  ].filter(([, cmd]) => cmd && cmd.trim() !== '');

  if (steps.length === 0) {
    return { passed: true, report: '(no lint/test/build gates configured for this project - skipped)' };
  }

  const lines = [];
  let passed = true;
  for (const [name, cmd] of steps) {
    const result = spawnSync(cmd, {
      cwd: projectFolder,
      shell: true,
      timeout: GATE_TIMEOUT_MS,
      encoding: 'utf8',
      maxBuffer: 10 * 1024 * 1024,
    });
    const timedOut = Boolean(result.error && result.error.code === 'ETIMEDOUT');
    const exitCode = timedOut ? null : result.status;
    const ok = !timedOut && exitCode === 0;

    lines.push(`$ ${cmd}   (${name})`);
    lines.push(`exit code: ${timedOut ? 'TIMEOUT' : exitCode}`);
    const output = `${result.stdout || ''}${result.stderr || ''}`.trim();
    lines.push(output ? output.slice(0, 8000) : '(no output)');
    lines.push('');

    if (!ok) {
      passed = false;
      break;
    }
  }
  return { passed, report: lines.join('\n').trim() };
}
