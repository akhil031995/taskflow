#!/usr/bin/env node
/**
 * taskflow-repomap MCP server.
 *
 * Repo-map / code-search over whatever directory this process was started
 * in (its cwd). run-agent.sh cd's into a ticket's project_folder before
 * starting Claude for that run, so cwd == the project being worked on -
 * exactly the "surgical navigation" target CLAUDE.md's Navigation section
 * asks for, without reading whole files or directories.
 *
 * Four tools:
 *   - search_symbols : ctags-like lookup by name/kind (function, class, ...)
 *   - search_code     : ranked keyword/snippet search (TF-IDF over chunks)
 *   - get_outline     : list every symbol in one file
 *   - refresh_index   : rebuild the index (call after large edits)
 *
 * The index is built lazily on first use and cached in memory for the life
 * of the process; there's no separate config, just the shared `dev_taskflow`
 * DB (via db.js) used for logging.
 *
 * Every call is timed and written to `mcp_invocations` - the same table
 * taskflow-mcp writes to - so this server's usage shows up in the app's
 * "AI Agent Logs" screen (By Tool / By Server tabs) exactly like the ticket
 * tools do. taskId is always null here (none of these tools take a ticket
 * id), so sessionLog/the Live Session drawer is intentionally NOT used -
 * it no-ops without a task_id anyway. Calls are still attributable to a
 * ticket via run_id -> runs.task_id (run_id is attached automatically by
 * logInvocation from TASKFLOW_RUN_ID, inherited from the run-agent.sh /
 * Claude process that spawned this server).
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { buildIndex, searchSymbols, searchCode, getOutline } from './repomap-index.js';
import { logInvocation } from './db.js';

const server = new McpServer({ name: 'taskflow-repomap-server', version: '1.0.0' });

const ROOT = process.env.REPOMAP_ROOT || process.cwd();
let index = null;

function ensureIndex() {
  if (!index) index = buildIndex(ROOT);
  return index;
}

function registerTool(name, description, schema, core) {
  server.tool(name, description, schema, async (args = {}) => {
    const startedAt = Date.now();
    try {
      const payload = await core(args);
      await logInvocation({
        tool: name,
        taskId: null,
        params: args,
        status: 'ok',
        result: payload,
        durationMs: Date.now() - startedAt,
      });
      return { content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] };
    } catch (err) {
      await logInvocation({
        tool: name,
        taskId: null,
        params: args,
        status: 'error',
        result: { error: err.message },
        durationMs: Date.now() - startedAt,
      });
      return {
        content: [{ type: 'text', text: JSON.stringify({ error: err.message }) }],
        isError: true,
      };
    }
  });
}

registerTool(
  'search_symbols',
  'Find functions/classes/methods/etc by name without opening any files. ' +
    'Substring match, ranked exact > prefix > contains. Returns {name, kind, file, line, signature}.',
  {
    query: z.string().min(1),
    kind: z.string().optional().describe('Filter to one kind, e.g. "function", "class", "interface".'),
    limit: z.number().int().positive().max(100).default(20),
  },
  async ({ query, kind, limit }) => {
    const idx = ensureIndex();
    return { root: idx.root, matches: searchSymbols(idx, query, { kind, limit }) };
  }
);

registerTool(
  'search_code',
  'Ranked keyword search over the whole project (TF-IDF across ~30-line chunks). ' +
    'Returns the top matching snippets with file + line range so you can open just that ' +
    'range instead of scanning full files.',
  {
    query: z.string().min(1),
    limit: z.number().int().positive().max(50).default(8),
  },
  async ({ query, limit }) => {
    const idx = ensureIndex();
    return { root: idx.root, results: searchCode(idx, query, { limit }) };
  }
);

registerTool(
  'get_outline',
  'List every tagged symbol (functions/classes/etc, with line numbers) in one file, ' +
    'so you know what to Read before opening the whole file.',
  { file: z.string().min(1).describe('Path relative to the project root.') },
  async ({ file }) => {
    const idx = ensureIndex();
    return { file, symbols: getOutline(idx, file) };
  }
);

registerTool(
  'refresh_index',
  'Rebuild the symbol/code index from disk. Call this after making edits so ' +
    'subsequent searches see the new content.',
  {},
  async () => {
    index = buildIndex(ROOT);
    return {
      root: index.root,
      files_indexed: index.filesIndexed,
      symbols_found: index.symbolCount,
      chunks_indexed: index.chunkCount,
      duration_ms: index.durationMs,
      built_at: index.builtAt,
    };
  }
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error(`[taskflow-repomap] ready on stdio, root=${ROOT}`);
}

main().catch((err) => {
  console.error('[taskflow-repomap] fatal:', err);
  process.exit(1);
});
