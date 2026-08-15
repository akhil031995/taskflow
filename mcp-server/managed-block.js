// Shared helper: write a delimited "managed" block into a target markdown
// file, replacing a prior block with the same markers if present, or
// appending it if not. Used by both the layered-standards flow
// (standards.js) and the project-primer flow (project-primer.js) to write
// auto-generated content into a project's CLAUDE.md without clobbering
// hand-authored content around it.
import fs from 'node:fs';
import { join } from 'node:path';

/**
 * Writes `body` into `existingFile` (or `<projectFolder>/CLAUDE.md` if none
 * given / it doesn't exist yet) between beginMarker/endMarker, replacing a
 * prior block with those exact markers if present. Returns the absolute path
 * written, or the original existingFile if projectFolder is unset.
 */
export function writeManagedBlock(projectFolder, existingFile, beginMarker, endMarker, body) {
  if (!projectFolder) return existingFile;

  const target = existingFile || join(projectFolder, 'CLAUDE.md');
  const block = `${beginMarker}\n${body}\n${endMarker}`;

  let current = '';
  if (fs.existsSync(target)) {
    current = fs.readFileSync(target, 'utf8');
  }

  const beginIdx = current.indexOf(beginMarker);
  const endIdx = current.indexOf(endMarker);
  let next;
  if (beginIdx !== -1 && endIdx !== -1 && endIdx > beginIdx) {
    next = current.slice(0, beginIdx) + block + current.slice(endIdx + endMarker.length);
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
