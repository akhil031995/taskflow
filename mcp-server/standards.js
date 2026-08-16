// Layered standards resolution: org-wide baseline (settings table) + a
// per-project override (project_standards table), merged into the effective
// standards document and written into the claimed project's CLAUDE.md so a
// headless Claude Code session picks it up as auto-loaded context.
//
// Mirrors resolve_effective_standards() in src/includes/functions.php - kept
// as a small standalone module (not shared code, since PHP and Node don't
// share a runtime) so both sides produce the same shape of document.
import { writeManagedBlock } from './managed-block.js';

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
  return writeManagedBlock(projectFolder, existingStandardsFile, BEGIN_MARKER, END_MARKER, effectiveMd);
}
