// Cached per-project context primer: a compact, size-bounded markdown
// summary of a project's structure (top-level layout, detected stack, entry
// points, key files by symbol density) generated from the same walk/tagger
// repomap-index.js already uses for the repo-map MCP tools.
//
// Cached in `project_primers`, keyed by a cheap fingerprint of the project's
// file tree (path/size/mtime, no content hashing) so a claim for a project
// whose tree hasn't changed since the last claim reuses the cached primer
// instead of re-walking + re-tagging the whole repo - "incremental refresh
// on change". When it *is* regenerated, it's written into the claimed
// project's CLAUDE.md as a managed block (mcp-server/managed-block.js), the
// same injection point finalizeClaim (db.js) already uses for layered
// standards, so a headless session picks it up as auto-loaded context at
// process start without the agent needing to re-derive project shape itself.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { buildIndex, walk } from './repomap-index.js';
import { detectStack, describeStack } from './stack-detect.js';
import { writeManagedBlock } from './managed-block.js';

const BEGIN_MARKER =
  '<!-- taskflow:primer:begin (auto-generated project primer, regenerates when the file tree changes - do not hand-edit) -->';
const END_MARKER = '<!-- taskflow:primer:end -->';

// Keeps the primer cheap to re-inject into a cached system prompt every run.
const MAX_PRIMER_CHARS = 4000;
const MAX_TOP_ENTRIES = 25;
const MAX_KEY_FILES = 12;
const MAX_SYMBOLS_PER_FILE = 5;

const IGNORE_TOP_LEVEL = new Set([
  'node_modules', 'vendor', '.git', '.svn', '.hg', 'dist', 'build',
  'coverage', '.next', '.nuxt', '.cache', '.idea', '.vscode', 'tmp', 'logs',
]);

/**
 * Cheap fingerprint of a project's file tree (relative path + size + mtime
 * per file, no content reads), used to decide whether the cached primer is
 * stale. Deliberately independent of buildIndex()'s heavier symbol/chunk
 * pass so a cache HIT never pays that cost.
 */
export function computeFingerprint(projectFolder) {
  const files = walk(projectFolder);
  const parts = [];
  for (const file of files) {
    let stat;
    try {
      stat = fs.statSync(file);
    } catch {
      continue; // removed mid-walk; skip rather than fail the whole fingerprint
    }
    parts.push(`${path.relative(projectFolder, file)}:${stat.size}:${Math.floor(stat.mtimeMs)}`);
  }
  parts.sort();
  return crypto.createHash('sha1').update(parts.join('\n')).digest('hex');
}

function topLevelEntries(projectFolder) {
  let entries;
  try {
    entries = fs.readdirSync(projectFolder, { withFileTypes: true });
  } catch {
    return [];
  }
  return entries
    .filter((e) => !IGNORE_TOP_LEVEL.has(e.name) && !e.name.startsWith('.'))
    .map((e) => (e.isDirectory() ? `${e.name}/` : e.name))
    .sort()
    .slice(0, MAX_TOP_ENTRIES);
}

/** Group indexed symbols by file and rank files by symbol density, as a proxy for "where the important code lives". */
function keyFiles(index) {
  const byFile = new Map();
  for (const sym of index.symbols) {
    if (!byFile.has(sym.file)) byFile.set(sym.file, []);
    byFile.get(sym.file).push(sym);
  }
  const ranked = [...byFile.entries()].sort((a, b) => b[1].length - a[1].length);
  return ranked.slice(0, MAX_KEY_FILES).map(([file, syms]) => {
    const names = syms.slice(0, MAX_SYMBOLS_PER_FILE).map((s) => s.name);
    const more = syms.length > MAX_SYMBOLS_PER_FILE ? `, +${syms.length - MAX_SYMBOLS_PER_FILE} more` : '';
    return `- \`${file}\` (${syms.length}): ${names.join(', ')}${more}`;
  });
}

/** Build the bounded primer markdown body (no managed-block markers) for a project. */
export function generatePrimer(projectFolder, projectName) {
  const index = buildIndex(projectFolder);
  const stack = describeStack(detectStack(projectFolder));
  const top = topLevelEntries(projectFolder);

  const sections = [`# Project Primer: ${projectName || path.basename(projectFolder)}`];

  if (stack) sections.push(`## Stack\n\n${stack}`);

  if (top.length) {
    sections.push(`## Top-level layout\n\n${top.map((e) => `- ${e}`).join('\n')}`);
  }

  const files = keyFiles(index);
  if (files.length) {
    sections.push(`## Key files (by symbol density)\n\n${files.join('\n')}`);
  }

  sections.push(
    `## Index stats\n\n${index.filesIndexed} files indexed, ${index.symbolCount} symbols tagged. ` +
      `Use the \`taskflow-repomap\` tools (search_symbols/search_code/get_outline) for the full index.`
  );

  let body = sections.join('\n\n');
  if (body.length > MAX_PRIMER_CHARS) {
    body = `${body.slice(0, MAX_PRIMER_CHARS)}\n\n_(primer truncated at ${MAX_PRIMER_CHARS} chars for cache-friendliness)_`;
  }
  return body;
}

/**
 * Returns the cached primer for `project` (regenerating + caching it first if
 * the file tree's fingerprint has changed since the cached copy, or none is
 * cached yet). Returns null if the project has no folder on this host.
 */
export async function getOrRefreshPrimer(pool, project) {
  const { projectId, projectFolder, projectName } = project;
  if (!projectFolder || !fs.existsSync(projectFolder)) return null;

  const fingerprint = computeFingerprint(projectFolder);
  const [[cached]] = await pool.query(
    `SELECT primer_md, fingerprint FROM project_primers WHERE project_id = ?`,
    [projectId]
  );
  if (cached && cached.fingerprint === fingerprint && cached.primer_md) {
    return cached.primer_md;
  }

  const primerMd = generatePrimer(projectFolder, projectName);
  await pool.query(
    `INSERT INTO project_primers (project_id, primer_md, fingerprint) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE primer_md = VALUES(primer_md), fingerprint = VALUES(fingerprint)`,
    [projectId, primerMd, fingerprint]
  );
  return primerMd;
}

/** Write `primerMd` into the project's CLAUDE.md (or STANDARDS.md) as a managed block. */
export function writePrimerBlock(projectFolder, existingFile, primerMd) {
  return writeManagedBlock(projectFolder, existingFile, BEGIN_MARKER, END_MARKER, primerMd);
}
