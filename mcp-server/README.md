# TaskFlow MCP Server

A Model Context Protocol (MCP) server that exposes TaskFlow's ticket database to
headless Claude Code agents. It connects directly to the same MySQL
`dev_taskflow` database as the PHP app and speaks MCP over **stdio**, so an agent
launcher (cron / n8n) can spawn it per run.

## Setup

```bash
cd mcp-server
npm install
cp .env.example .env      # set DB_HOST/PORT/NAME/USER/PASS
node index.js             # starts the stdio server (for a smoke test)
```

## Register with Claude Code

```bash
claude mcp add taskflow-mcp node /absolute/path/to/taskflow/mcp-server/index.js
```

The host orchestrator (cron / n8n) then runs [`../run-agent.sh`](../run-agent.sh)
every ~30 min. That script claims the ticket itself first (via `claim-ticket.js`,
below) so it can `cd` into the claimed ticket's `project_folder` *before*
booting headless Claude there — that's what makes Claude auto-load that
project's own `CLAUDE.md`/`STANDARDS.md` instead of TaskFlow's. Claude then
connects here (from that directory) for status updates and comments.

Or in an MCP client config:

```json
{
  "mcpServers": {
    "taskflow": {
      "command": "node",
      "args": ["/absolute/path/to/taskflow/mcp-server/index.js"],
      "env": {
        "DB_HOST": "host.docker.internal",
        "DB_PORT": "3306",
        "DB_NAME": "dev_taskflow",
        "DB_USER": "root",
        "DB_PASS": "..."
      }
    }
  }
}
```

## Tools

| Tool | Parameters | Behavior |
|------|-----------|----------|
| `get_highest_priority_ticket` | (none) | Selects the top ticket where `ai_execution_status` IS NULL or `pending` (plus `rate-limited-paused` tickets, resumed first), ordered by `priority ASC, id ASC`. **Atomically** locks it (`in-progress` + `ai_locked_at = NOW()`) inside a `SELECT ... FOR UPDATE` transaction to prevent races. Returns `id, title, description, acceptance_criteria, task_type, project_id, priority, ai_execution_status, project_name, project_folder, project_url, standards_file, human_comments`, or `{}` if none. `standards_file` is the claimed project's `CLAUDE.md`/`STANDARDS.md` path, or `null`. `human_comments` is `[{author, text, created_at}]` from the ticket's detail-modal comment thread, oldest first, or `[]`. In the normal run-agent.sh flow this ticket is already claimed by `claim-ticket.js`, so Claude should not call this tool itself (see `AGENT_PROMPT.md`). |
| `update_ticket_status` | `task_id:int`, `status:'completed'\|'blocked'\|'rate-limited-paused'` | Sets `ai_execution_status` and clears `ai_locked_at`. |
| `add_ticket_comment` | `task_id:int`, `comment_text:string` | Appends a timestamped line to `ai_comments` (prior notes preserved). |
| `create_ticket` | `title:string`, `description:string`, `priority:int(1-3)`, `task_type:enum`, `project_id:int` | Inserts a new row with `created_by = 'ai'`, `ai_execution_status = 'pending'`. Used to decompose oversized work or log tech debt. |

## taskflow-repomap (separate server, `repomap-server.js`)

A second, independent stdio MCP server for repo-map / code-search — the tool
CLAUDE.md's Navigation section tells agents to use instead of blind-scanning a
project. It has no DB dependency: it indexes whatever directory it's started
in (its `cwd`), so it works for any claimed ticket's `project_folder`, not
just TaskFlow. Symbol tagging is a lightweight regex tagger (ctags/tree-sitter
aren't installable on the host without sudo); code search is a TF-IDF ranked
index over ~30-line chunks — both built in-process, in memory, no network
calls.

```bash
claude mcp add taskflow-repomap -s local -- node /absolute/path/to/taskflow/mcp-server/repomap-server.js
```

Register it once per host the same way as `taskflow-mcp` above (`-s local`
scopes it to this project directory, matching how `taskflow-mcp` itself is
registered — see `~/.claude.json`'s `projects` entry for this repo).

| Tool | Parameters | Behavior |
|------|-----------|----------|
| `search_symbols` | `query:string`, `kind?:string`, `limit?:int` | ctags-like lookup by name (function/class/method/...), ranked exact > prefix > substring. |
| `search_code` | `query:string`, `limit?:int` | Ranked keyword search (TF-IDF) across chunked files; returns `file`, `start_line`, `end_line`, `snippet` per hit. |
| `get_outline` | `file:string` (relative to root) | Lists every tagged symbol in one file with line numbers. Rejects paths that resolve outside the project root. |
| `refresh_index` | (none) | Rebuilds the in-memory index from disk; call after edits since the index isn't file-watched. |

Source: `repomap-index.js` (pure indexing/search logic, no MCP dependency) and
`repomap-server.js` (MCP tool wiring, same `registerTool` pattern as `index.js`).

## Host scripts (not MCP tools — run directly by run-agent.sh)

| Script | Purpose |
|--------|---------|
| `claim-ticket.js` | Runs the exact same atomic claim as `get_highest_priority_ticket`, printing the claimed ticket as one line of JSON on stdout. run-agent.sh calls this *before* starting Claude so it can `cd` into `project_folder` first. |
| `claim-ticket-by-id.js <task_id>` | Claims one SPECIFIC ticket by id, bypassing priority order (same eligibility rule and atomic lock as above). Used by `run-agent.sh -t`. Exits `2` if the id doesn't exist, `3` if it exists but isn't currently claimable (e.g. already in-progress/completed/on hold). |
| `block-ticket.js <task_id> "<reason>"` | Marks a ticket `blocked`/On Hold directly, for failures the shell detects pre-flight (e.g. an invalid `project_folder`) — before Claude ever starts. |
| `requeue-ticket.js <task_id> <resumed:0\|1> "<reason>"` | Gives a claimed ticket back to the queue (fresh `pending`, or `rate-limited-paused` if `resumed=1`) without marking it blocked/completed. Used when run-agent.sh's per-project lock for the ticket's project is already held by a concurrently-running instance. |
| `reconcile.js` | Recovers tickets orphaned `in-progress` by a crashed/killed prior session, flipping them to `rate-limited-paused` so the next run resumes them. Before touching a ticket it checks whether its project's per-project lock (`TASKFLOW_LOCK_DIR`) is currently held by a live sibling `run-agent.sh` instance and skips it if so - `in-progress` there is a genuinely active session, not an orphan. Tracks each ticket's `resume_attempts`; after `RESUME_ATTEMPT_LIMIT` (env var, default 3) consecutive orphaned resumes it auto-blocks the ticket (On Hold) with a comment instead of resuming it again, so a perpetually-failing ticket can't starve the queue. The counter resets whenever the ticket is next claimed fresh (not resumed). |
| `start-run.js <task_id\|-> <project_id\|->` | Inserts the `runs` row for the session about to start and prints its id. Called by run-agent.sh *before* starting Claude, which exports the id as `TASKFLOW_RUN_ID` so the MCP server subprocess (inherits the shell env) tags every `mcp_invocations`/`ai_session_logs` row it writes with `run_id` (migration `011_run_tagging.sql`) - see `logInvocation`/`sessionLog` in `db.js`. |
| `record-run.js <run_id\|-> <task_id\|-> <project_id\|-> <run_log> <exit_code> <started_at_iso>` | Parses a finished session's `--output-format stream-json` log for the terminal `result` event's token usage/cost and UPDATEs the `runs` row `start-run.js` created (finish time, exit code, tokens, `outcome`: `success`/`error`/`rate_limited`). Falls back to inserting a new row if `run_id` is `-` or the row is gone. Called by run-agent.sh after every session; best-effort - never fails the run-agent.sh iteration. |
| `bootstrap-standards.js` | One-time enrollment for existing projects: detects each project's stack (`package.json` / `composer.json` / `Dockerfile`) and writes a starter `project_standards.override_md` for any project that doesn't have one yet. Idempotent - already-enrolled projects (non-empty `override_md`) are skipped, so it's safe to re-run. Run with `npm run bootstrap-standards` or `node bootstrap-standards.js`. |

## Notes

- **stdout is the MCP channel** — the server logs only to stderr.
- Priority is numeric: **1 = High, 2 = Medium, 3 = Low** (sorts ascending).
- The server shares the `homeserver-net` MySQL; from inside a container use
  `DB_HOST=host.docker.internal` (or the `mysql` service name on that network).
- Each project's standards file is found by convention: `CLAUDE.md` then
  `STANDARDS.md` at the root of its `folder_path`. The per-project override
  layer lives in `project_standards.override_md` (edited from the app's
  Standards tab, or seeded by `bootstrap-standards.js`) and is merged with the
  org baseline into that file at ticket-claim time - see `standards.js`.
- `project-primer.js` generates a cached, size-bounded per-project primer
  (top-level layout, detected stack, key files by symbol density) from the
  same walk/tagger `repomap-index.js` uses. Cached in `project_primers`,
  keyed by a fingerprint of the file tree so it's only regenerated when that
  tree actually changes; written into the same `CLAUDE.md` as a second
  managed block alongside standards (see `finalizeClaim` in `db.js`) and
  shown read-only on the project page's Primer tab.
