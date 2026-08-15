// Layered standards resolution: org-wide baseline (settings table) + a
// per-project override (project_standards table), merged into the effective
// standards document and written into the claimed project's CLAUDE.md so a
// headless Claude Code session picks it up as auto-loaded context.
//
// Mirrors resolve_effective_standards() in src/includes/functions.php - kept
// as a small standalone module (not shared code, since PHP and Node don't
// share a runtime) so both sides produce the same shape of document.
import fs from 'node:fs';
import { join } from 'node:path';

const BEGIN_MARKER = '<!-- taskflow:standards:begin (auto-generated, edit via the project\'s Standards tab, not by hand) -->';
const END_MARKER = '<!-- taskflow:standards:end -->';

/** Merge the org baseline with a project's override. Empty layers are omitted. */
export async function resolveEffectiveStandards(pool, projectId) {
  const [[baselineRow]] = await pool.query(
    `SELECT value FROM settings WHERE setting_key = 'org_standards_baseline'`
  );
  const [[overrideRow]] = await pool.query(
    `SELECT override_md FROM project_standards WHERE project_id = ?`,
    [projectId]
  );

  const baseline = (baselineRow?.value ?? '').trim();
  const override = (overrideRow?.override_md ?? '').trim();

  const parts = [];
  if (baseline) parts.push(`## Org Baseline Standards\n\n${baseline}`);
  if (override) parts.push(`## Project Overrides\n\n${override}`);
  return parts.join('\n\n');
}

/**
 * Write the resolved effective standards into projectFolder's CLAUDE.md as a
 * clearly delimited managed block, preserving any hand-authored content
 * around it. Creates CLAUDE.md if the project has neither CLAUDE.md nor
 * STANDARDS.md yet. Returns the absolute path written, or null if there was
 * nothing to write (empty effective standards and no file to update).
 */
export function writeResolvedStandards(projectFolder, existingStandardsFile, effectiveMd) {
  if (!projectFolder) return existingStandardsFile;

  const target = existingStandardsFile || join(projectFolder, 'CLAUDE.md');
  const block = `${BEGIN_MARKER}\n${effectiveMd}\n${END_MARKER}`;

  let current = '';
  if (fs.existsSync(target)) {
    current = fs.readFileSync(target, 'utf8');
  }

  const beginIdx = current.indexOf(BEGIN_MARKER);
  const endIdx = current.indexOf(END_MARKER);
  let next;
  if (beginIdx !== -1 && endIdx !== -1 && endIdx > beginIdx) {
    next = current.slice(0, beginIdx) + block + current.slice(endIdx + END_MARKER.length);
  } else if (current.trim() === '') {
    next = `${block}\n`;
  } else {
    next = `${current.replace(/\s*$/, '')}\n\n${block}\n`;
  }

  if (next !== current) {
    fs.writeFileSync(target, next, 'utf8');
  }
  return target;
}
