// Repo-map / code-search index for the taskflow-repomap MCP server.
//
// Pure filesystem + regex implementation - no external binaries (ctags is not
// installed on this box and there's no sudo to add it) and no network calls
// (no embedding API). Two structures are built from the same file walk:
//
//   - symbols: a ctags-like table (name, kind, file, line) from per-language
//     regex taggers, for exact/prefix symbol lookups.
//   - chunks:  ~30-line windows of every indexed file with a TF-IDF weighted
//     token index, for ranked keyword/snippet search.
//
// The index is root-scoped (root = wherever the MCP server process's cwd is,
// i.e. whatever project_folder run-agent.sh cd'd into) and built lazily, then
// cached in memory until refresh_index() is called again.
import fs from 'node:fs';
import path from 'node:path';

const IGNORE_DIRS = new Set([
  'node_modules', 'vendor', '.git', '.svn', '.hg', 'dist', 'build',
  'coverage', '.next', '.nuxt', '.cache', '.idea', '.vscode', 'tmp',
  'logs', '.repomap-cache',
]);

const MAX_FILE_BYTES = 1_000_000;
const MAX_FILES = 20_000;
const CHUNK_LINES = 30;
const CHUNK_OVERLAP = 5;

const EXT_LANG = {
  '.php': 'php',
  '.js': 'js', '.jsx': 'js', '.mjs': 'js', '.cjs': 'js',
  '.ts': 'ts', '.tsx': 'ts',
  '.py': 'py',
  '.rb': 'rb',
  '.go': 'go',
  '.java': 'java',
  '.rs': 'rust',
};
// Extensions searched for text/chunk matches even though there's no symbol
// tagger for them.
const TEXT_ONLY_EXT = new Set(['.md', '.sql', '.json', '.yml', '.yaml', '.sh', '.css', '.html']);

const JS_KEYWORDS = new Set([
  'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'else',
  'do', 'try', 'finally', 'with',
]);

// Each entry: [regex with one capture group for the symbol name, kind].
// Regexes run per-line; they're heuristic taggers, not a real parser.
const SYMBOL_PATTERNS = {
  php: [
    [/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/, 'class'],
    [/^\s*interface\s+(\w+)/, 'interface'],
    [/^\s*trait\s+(\w+)/, 'trait'],
    [/^\s*(?:public\s+|private\s+|protected\s+|static\s+)*function\s+(\w+)\s*\(/, 'function'],
    [/^\s*(?:const\s+)(\w+)\s*=/, 'const'],
  ],
  js: [
    [/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s+(\w+)\s*\(/, 'function'],
    [/^\s*(?:export\s+)?class\s+(\w+)/, 'class'],
    [/^\s*(?:export\s+)?const\s+(\w+)\s*=\s*(?:async\s*)?\(?[^=]*=>/, 'function'],
    [/^\s*(?:export\s+)?const\s+(\w+)\s*=\s*require\(/, 'const'],
    [/^\s*(?:async\s+)?(\w+)\s*\([^)]*\)\s*\{\s*$/, 'method'],
  ],
  py: [
    [/^\s*def\s+(\w+)\s*\(/, 'function'],
    [/^\s*class\s+(\w+)/, 'class'],
  ],
  rb: [
    [/^\s*def\s+(\w+)/, 'function'],
    [/^\s*class\s+(\w+)/, 'class'],
    [/^\s*module\s+(\w+)/, 'module'],
  ],
  go: [
    [/^\s*func\s+(?:\([^)]*\)\s*)?(\w+)\s*\(/, 'function'],
    [/^\s*type\s+(\w+)\s+struct/, 'struct'],
  ],
  java: [
    [/^\s*(?:public|private|protected)?\s*(?:static\s+)?(?:final\s+)?class\s+(\w+)/, 'class'],
    [/^\s*(?:public|private|protected)\s+[\w<>\[\]]+\s+(\w+)\s*\(/, 'method'],
  ],
  rust: [
    [/^\s*(?:pub\s+)?fn\s+(\w+)/, 'function'],
    [/^\s*(?:pub\s+)?struct\s+(\w+)/, 'struct'],
    [/^\s*(?:pub\s+)?enum\s+(\w+)/, 'enum'],
  ],
};
SYMBOL_PATTERNS.ts = [
  ...SYMBOL_PATTERNS.js,
  [/^\s*(?:export\s+)?interface\s+(\w+)/, 'interface'],
  [/^\s*(?:export\s+)?type\s+(\w+)\s*=/, 'type'],
];

const STOPWORDS = new Set([
  'the', 'a', 'an', 'is', 'are', 'to', 'of', 'in', 'on', 'for', 'and', 'or',
  'this', 'that', 'it', 'be', 'as', 'with', 'not', 'null',
]);

function tokenize(text) {
  return (text.toLowerCase().match(/[a-z0-9_]+/g) || []).filter(
    (t) => t.length >= 2 && !STOPWORDS.has(t)
  );
}

let gitignoreCache = new Map();
function loadGitignoreMatchers(root) {
  if (gitignoreCache.has(root)) return gitignoreCache.get(root);
  const file = path.join(root, '.gitignore');
  const matchers = [];
  if (fs.existsSync(file)) {
    for (const raw of fs.readFileSync(file, 'utf8').split('\n')) {
      const line = raw.trim();
      if (!line || line.startsWith('#')) continue;
      const pattern = line.replace(/^\/+/, '').replace(/\/+$/, '');
      if (!pattern) continue;
      if (pattern.includes('*')) {
        const re = new RegExp('^' + pattern.split('*').map(escapeRe).join('.*') + '$');
        matchers.push((name) => re.test(name));
      } else {
        matchers.push((name) => name === pattern);
      }
    }
  }
  gitignoreCache.set(root, matchers);
  return matchers;
}
function escapeRe(s) {
  return s.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
}

function isIgnored(name, gitignoreMatchers) {
  if (IGNORE_DIRS.has(name)) return true;
  if (name.startsWith('.') && name !== '.gitignore') return true;
  return gitignoreMatchers.some((m) => m(name));
}

function walk(root) {
  const gitignoreMatchers = loadGitignoreMatchers(root);
  const files = [];
  const stack = [root];
  while (stack.length && files.length < MAX_FILES) {
    const dir = stack.pop();
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch {
      continue;
    }
    for (const entry of entries) {
      if (isIgnored(entry.name, gitignoreMatchers)) continue;
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        stack.push(full);
      } else if (entry.isFile()) {
        const ext = path.extname(entry.name).toLowerCase();
        if (EXT_LANG[ext] || TEXT_ONLY_EXT.has(ext)) files.push(full);
      }
    }
  }
  return files;
}

function tagSymbols(relPath, lang, lines) {
  const patterns = SYMBOL_PATTERNS[lang];
  if (!patterns) return [];
  const symbols = [];
  lines.forEach((line, i) => {
    for (const [re, kind] of patterns) {
      const m = re.exec(line);
      if (!m || !m[1]) continue;
      if (kind === 'method' && JS_KEYWORDS.has(m[1])) continue;
      symbols.push({ name: m[1], kind, file: relPath, line: i + 1, signature: line.trim().slice(0, 200) });
      break; // one tag per line is enough
    }
  });
  return symbols;
}

function chunkFile(relPath, lines) {
  const chunks = [];
  const step = CHUNK_LINES - CHUNK_OVERLAP;
  for (let start = 0; start < lines.length; start += step) {
    const end = Math.min(start + CHUNK_LINES, lines.length);
    const text = lines.slice(start, end).join('\n');
    if (text.trim()) {
      chunks.push({ file: relPath, startLine: start + 1, endLine: end, text });
    }
    if (end === lines.length) break;
  }
  return chunks;
}

/**
 * Build a fresh index rooted at `root`. Returns { root, symbols, chunks,
 * docFreq, chunkCount, filesIndexed, durationMs }.
 */
export function buildIndex(root) {
  const startedAt = Date.now();
  const absRoot = path.resolve(root);
  const files = walk(absRoot);

  const symbols = [];
  const chunks = [];
  let filesIndexed = 0;

  for (const file of files) {
    let stat;
    try {
      stat = fs.statSync(file);
    } catch {
      continue;
    }
    if (stat.size > MAX_FILE_BYTES) continue;

    let content;
    try {
      content = fs.readFileSync(file, 'utf8');
    } catch {
      continue;
    }
    // Skip binary-ish files (a NUL byte in the first 1000 chars is a cheap tell).
    if (content.slice(0, 1000).indexOf(String.fromCharCode(0)) !== -1) continue;

    const relPath = path.relative(absRoot, file);
    const ext = path.extname(file).toLowerCase();
    const lang = EXT_LANG[ext];
    const lines = content.split('\n');

    if (lang) symbols.push(...tagSymbols(relPath, lang, lines));
    chunks.push(...chunkFile(relPath, lines));
    filesIndexed++;
  }

  // Document frequency for TF-IDF, computed once so every search is a cheap
  // dot-product instead of a re-scan of the filesystem.
  const docFreq = new Map();
  const chunkTokens = chunks.map((c) => {
    const tf = new Map();
    for (const tok of tokenize(c.text)) tf.set(tok, (tf.get(tok) || 0) + 1);
    for (const tok of tf.keys()) docFreq.set(tok, (docFreq.get(tok) || 0) + 1);
    return tf;
  });

  return {
    root: absRoot,
    symbols,
    chunks,
    chunkTokens,
    docFreq,
    chunkCount: chunks.length,
    filesIndexed,
    symbolCount: symbols.length,
    durationMs: Date.now() - startedAt,
    builtAt: new Date().toISOString(),
  };
}

export function searchSymbols(index, query, { kind, limit = 20 } = {}) {
  const q = query.toLowerCase();
  const scored = [];
  for (const sym of index.symbols) {
    if (kind && sym.kind !== kind) continue;
    const name = sym.name.toLowerCase();
    let score;
    if (name === q) score = 3;
    else if (name.startsWith(q)) score = 2;
    else if (name.includes(q)) score = 1;
    else continue;
    scored.push({ ...sym, score });
  }
  scored.sort((a, b) => b.score - a.score || a.name.localeCompare(b.name));
  return scored.slice(0, limit);
}

export function searchCode(index, query, { limit = 8 } = {}) {
  const queryTokens = tokenize(query);
  if (queryTokens.length === 0) return [];
  const N = index.chunkCount || 1;
  const idf = new Map();
  for (const tok of queryTokens) {
    const df = index.docFreq.get(tok) || 0;
    idf.set(tok, Math.log((N + 1) / (df + 1)) + 1);
  }

  const qLower = query.toLowerCase();
  const scored = [];
  index.chunks.forEach((chunk, i) => {
    const tf = index.chunkTokens[i];
    let score = 0;
    for (const tok of queryTokens) {
      const count = tf.get(tok) || 0;
      if (count > 0) score += count * idf.get(tok);
    }
    if (score === 0) return;
    if (chunk.file.toLowerCase().includes(qLower)) score += 2;
    if (chunk.text.toLowerCase().includes(qLower)) score += 1;
    scored.push({ chunk, score });
  });

  scored.sort((a, b) => b.score - a.score);

  // Keep at most one chunk per file among the top results so a single huge
  // file can't crowd out everything else; take the next-best chunk from a
  // different file instead of a second window of the same one.
  const seen = new Set();
  const results = [];
  for (const { chunk, score } of scored) {
    if (seen.has(chunk.file)) continue;
    seen.add(chunk.file);
    results.push({
      file: chunk.file,
      start_line: chunk.startLine,
      end_line: chunk.endLine,
      score: Math.round(score * 100) / 100,
      snippet: chunk.text.slice(0, 1000),
    });
    if (results.length >= limit) break;
  }
  return results;
}

export function getOutline(index, relFile) {
  const normalized = relFile.replace(/^\.?\/+/, '');
  const target = path.resolve(index.root, normalized);
  if (target !== index.root && !target.startsWith(index.root + path.sep)) {
    throw new Error(`Path escapes project root: ${relFile}`);
  }
  return index.symbols
    .filter((s) => s.file === normalized || s.file === path.relative(index.root, target))
    .sort((a, b) => a.line - b.line);
}
