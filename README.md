# TaskFlow

A lightweight, self-hosted, single-user project tracker. No auth, no heavy
frameworks - just vanilla PHP + MySQL, a Dockerized runtime, and a Tailwind /
vanilla-JS frontend.

## Features

- **Dashboard** - stat widgets (projects, tasks, completion %), a unified
  *In Progress* feed across all projects, a live **Quick Search** filter, and a
  **Project Hub** grid.
- **Kanban board** per project - four columns (Pending, In Progress, On Hold,
  Completed) with **drag-and-drop** (SortableJS) that persists status + ordering
  over AJAX. Cards show a color-coded priority strip.
- **Tabbed Notes** per project - a **Quill** WYSIWYG editor with **AJAX
  auto-save** (debounced ~1.2s after you stop typing).
- **Quick Links** dropdown in the navbar (add / open / delete bookmarks).
- **Dark mode** toggle, persisted to `localStorage`.
- **1-Click Backup** - download the whole DB as `.sql` (or `.json`).

## Tech stack

| Layer      | Choice                                               |
|------------|------------------------------------------------------|
| Backend    | Vanilla PHP 8.4 (single `api.php` action router)     |
| Database   | MySQL 8 (external, shared with the MCP server)        |
| Frontend   | HTML5, vanilla JS, Tailwind (CDN), SortableJS, Quill  |
| Runtime    | Docker Compose (official `php:8.4-apache`)            |
| AI plane   | Node.js MCP server (`mcp-server/`) + headless CLI     |
| Auth       | None (local, single-user)                            |

Served on **port 80** inside the container (fronted by the Caddy reverse proxy).

## Quick start

This build connects to an **external** MySQL server (service `mysql` on the
`homeserver-net` Docker network - see `docker-compose.yml`). It does **not** ship
its own database container.

```bash
cd taskflow
cp .env.example .env      # then edit credentials as needed
docker compose up --build
```

The app is exposed on port 80 inside the `homeserver-net` network (reach it via
your reverse proxy). To test standalone on the host, add a `ports:` mapping to
the `taskflow-dev` service, e.g. `ports: ["8080:80"]`, then open
<http://localhost:8080>.

## Configuration (`.env`)

Credentials live in a git-ignored `.env` file (copy from `.env.example`).
Docker Compose loads it automatically for the `${VAR}` substitutions in
`docker-compose.yml`, and the values are passed through to PHP as environment
variables.

| Variable  | Default         | Purpose                                        |
|-----------|-----------------|------------------------------------------------|
| `DB_HOST` | `mysql`         | MySQL host (service name on `homeserver-net`)  |
| `DB_PORT` | `3306`          | MySQL port                                     |
| `DB_NAME` | `dev_taskflow`  | App database (auto-created if missing)         |
| `DB_USER` | `root`          | MySQL user                                     |
| `DB_PASS` | `root`          | MySQL password - **change before exposing**    |

`.env` is never committed; `.env.example` is the tracked template. See
`.gitignore` for what else is excluded (backups, editor cruft, deps).

## Automatic migrations (self-bootstrapping)

On **every container start / restart**, the entrypoint (`docker/entrypoint.sh`)
runs the migrator (`src/config/migrate.php`), which:

1. Waits for the MySQL server to accept connections (`--wait`, up to 60s).
2. **Creates the database (`DB_NAME`) if it does not exist.**
3. Creates a `schema_migrations` ledger table if missing.
4. Applies any pending `src/migrations/*.sql` files, in filename order, exactly
   once each.

So a fresh environment needs **no manual SQL import** - just start the container.
Because `src/` is live-mounted, adding a new `migrations/NNN_*.sql` file and
running `docker compose restart taskflow-dev` applies it.

Run migrations manually if you like:

```bash
docker compose exec taskflow-dev php config/migrate.php
```

The sample/seed content lives in `002_seed_data.sql` (idempotent `INSERT
IGNORE`). Delete that file before first boot if you want an empty database.

## Project layout

```
taskflow/
├── docker-compose.yml         # taskflow-dev web service (external MySQL)
├── Dockerfile                 # PHP 8.2 + pdo_mysql + migration entrypoint
├── .env.example               # credentials template (copy to .env)
├── .gitignore
├── docker/
│   └── entrypoint.sh          # waits for DB, runs migrations, starts Apache
├── README.md
└── src/                       # Apache docroot (live-mounted)
    ├── index.php              # Dashboard
    ├── project.php            # Kanban board + Notes
    ├── api.php                # JSON action router (all AJAX endpoints)
    ├── .htaccess
    ├── migrations/            # versioned *.sql, applied automatically
    │   ├── 001_initial_schema.sql
    │   └── 002_seed_data.sql
    ├── config/
    │   ├── db.php             # PDO connections (app + server-level)
    │   └── migrate.php        # self-bootstrapping migration runner
    ├── includes/
    │   ├── functions.php      # shared queries + helpers
    │   ├── header.php         # <head> + navbar (Quick Links, Backup, theme)
    │   ├── footer.php
    │   └── task_card.php      # reusable Kanban card partial
    └── assets/
        ├── app.js             # api()/toast(), theme, quick links
        └── project.js         # drag-and-drop + notes auto-save
```

## API (all under `api.php?action=…`, JSON in / JSON out)

| Action            | Purpose                                  |
|-------------------|------------------------------------------|
| `task.create`     | Create a task                            |
| `task.update`     | Edit a task's fields                     |
| `task.move`       | Drag-and-drop: set status + column order |
| `task.delete`     | Delete a task                            |
| `note.create`     | New note tab                             |
| `note.save`       | Auto-save note content                   |
| `note.rename`     | Rename a note tab                        |
| `note.delete`     | Delete a note                            |
| `link.create` / `link.delete` | Quick Links                  |
| `project.create` / `project.delete` | Projects (cascade)     |
| `backup`          | `GET` - download `.sql` / `.json` dump   |

All AJAX calls fail gracefully (JSON error → toast) and never force a full page
reload, except task *edits*, which reload once to re-render moved/re-colored
cards.

## Data model

| Table         | Purpose                                                    |
|---------------|------------------------------------------------------------|
| `projects`    | Top-level containers (name, description, accent color)     |
| `tasks`       | Kanban cards (status, priority, position, FK → projects)   |
| `notes`       | Rich-text note tabs (Quill HTML, FK → projects)            |
| `quick_links` | Global navbar bookmarks                                    |
| `task_comments` | Human comments on a ticket (author, text, timestamp), FK → tasks. The agent reads these on its next claim; see `human_comments` in the MCP server docs. |

`tasks` and `notes` cascade-delete with their project.

## Backup & restore

Click **💾 Backup** in the navbar (or hit `api.php?action=backup&format=sql`)
to download a full `.sql` dump; use `&format=json` for a structured export.
Restore a SQL dump with your normal MySQL tooling, e.g.:

```bash
docker compose exec -T taskflow-dev sh -c \
  'mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"' < taskflow_backup_YYYY-MM-DD_HHMMSS.sql
```

## Version control

The repo is git-ready but not initialized. To start tracking:

```bash
git init && git add . && git status   # confirm .env is NOT listed
```

`.env` and backup dumps are git-ignored; versioned migrations under
`src/migrations/` are kept.

### Releases (build only on tag)

CI (`.github/workflows/publish.yml`) builds and pushes the Docker image to
GHCR **only when a version tag is pushed** - regular branch commits never
trigger a build. Cut a release by tagging:

```bash
git tag v1.0.0
git push origin v1.0.0
```

This publishes `ghcr.io/akhil031995/taskflow` tagged `1.0.0`, `1.0`, and `1`.
The workflow can also be run manually from the Actions tab (`workflow_dispatch`).

## AI Agent Control Plane

TaskFlow doubles as the backlog for autonomous coding runs. Migration
`003_ai_agent_fields.sql` extends `tasks` with numeric `priority` (1-High,
2-Medium, 3-Low), `task_type`, `acceptance_criteria`, `ai_execution_status`,
`ai_locked_at`, `ai_comments`, and `created_by`.

- **Redesigned dark app-shell** (sidebar + topbar): Dashboard, Projects,
  **Settings**, Backup, and **AI Agent Logs**.
- **Board UI** shows `TF-###` keys, priority/type/`AI DRAFT` badges, a 🔒 lock
  (drag disabled) while an agent is mid-run, interactive acceptance-criteria
  checkboxes, and a recent *AI Comments* panel with timestamps (from `ai_comments`).
- **MCP Settings & System Prompts** (`settings.php`): edit poll interval, launch
  command, the agent system prompt, and operating rules (stored in `settings`).
- **AI Agent Logs** (`logs.php`): a live, auto-refreshing table of every MCP tool
  invocation with timestamp, ticket, status, and duration, grouped by **run**
  (a `<select>` filters to one run-agent.sh execution at a time; "All runs" is
  the default). Migration `004_mcp_control_plane.sql` adds the `settings` and
  `mcp_invocations` tables; the MCP server writes a row per tool call.
- **Per-run tracking** (migration `010_runs_table.sql` adds `runs`;
  `011_run_tagging.sql` adds `run_id` to `mcp_invocations`/`ai_session_logs`
  plus `runs.outcome`): `run-agent.sh` calls `mcp-server/start-run.js` to
  insert a `runs` row (`started_at`) *before* launching Claude and exports its
  id as `TASKFLOW_RUN_ID`, which the MCP server subprocess inherits and stamps
  onto every invocation/session-log row it writes that session
  (`logInvocation`/`sessionLog` in `db.js`). Claude runs with
  `--output-format stream-json`, and after the session `record-run.js` parses
  the log's terminal `result` event for token usage/`total_cost_usd` and
  UPDATEs that same `runs` row with `finished_at`, `exit_code`, and `outcome`
  (`success`/`error`/`rate_limited`). Rows written before this migration have
  `run_id = NULL` and render under an "(unassigned)" run in the Logs UI.
  Per-ticket cost shows as a `$` badge on board cards; per-project totals show
  on the project header and the dashboard's Project Hub cards
  (`get_task_run_cost`/`get_project_run_cost`/`get_all_project_run_costs` in
  `includes/functions.php`).

### Live session monitoring

Watch active agent runs in real time (migration `006_live_session_logs.sql`
adds `ai_session_logs`):

- **Active Code Sessions** panel (bottom-left, every page) lists in-progress /
  initializing / paused sessions with pulsing status dots
  (`api.php?action=sessions.active`).
- Selecting a session opens the **Live Claude Session drawer**: AI Conversation
  (thoughts/status), Live Code View (colored diffs with per-file tabs), and a
  Terminal console — streamed over **Server-Sent Events**
  (`api.php?action=session.stream&task_id=X`) via `assets/session_monitor.js`,
  which auto-scrolls the terminal and reconnects on drop.
- The MCP server emits `status` / `thought` / `terminal` events per tool call.
  An external **CLI wrapper** can push richer events (file reads/writes, command
  output, unified diffs) to the ingest endpoint:

  ```bash
  curl -s "$TASKFLOW_URL/api.php?action=session.log" \
    -H 'Content-Type: application/json' \
    -d '{"task_id":4,"log_type":"code_diff","file_path":"src/foo.php","content":"@@ -1 +1 @@\n-old\n+new"}'
  ```

  `log_type` is one of `thought`, `terminal`, `code_diff`, `status`.
- **`mcp-server/`** is a Node.js MCP server (mysql2) exposing four tools
  (`get_highest_priority_ticket`, `update_ticket_status`, `add_ticket_comment`,
  `create_ticket`) over stdio. See [mcp-server/README.md](mcp-server/README.md).
- **`CLAUDE.md`** holds the agent's operating rules (surgical navigation, no git
  commits, decomposition). **`AGENT_PROMPT.md`** is the exact per-run loop.

### Autonomous execution (host orchestration)

The MCP server does **not** launch Claude. The [`run-agent.sh`](run-agent.sh)
loop on the **host** boots the Claude Code CLI headlessly; as Claude starts it
connects to the registered `taskflow-mcp` server, calls
`get_highest_priority_ticket`, and reads/writes files directly in the ticket's
`project_folder`.

**One-time host setup**

```bash
npm install -g @anthropic-ai/claude-code
claude login                                   # authenticate once, interactively
claude mcp add taskflow-mcp node /absolute/path/to/taskflow/mcp-server/index.js
```

**The runner** — [`run-agent.sh`](run-agent.sh) (project root, `chmod +x`) is a
self-contained scheduler. It:

1. Takes a **per-project** lock via `flock` (one file per `project_id` under
   `/tmp/taskflow-agent-locks/`, auto-released on exit — no stale lock even
   after a kill) right before working a claimed ticket, so multiple
   `run-agent.sh` instances can run at once and make progress on **different**
   projects in parallel, while guaranteeing no two ever touch the same
   project's working directory concurrently. If a claimed ticket's project is
   already locked by another instance, the ticket is handed straight back to
   the queue and that instance retries shortly after (see `TASKFLOW_SKIP_RETRY`
   below), rather than blocking.
2. **Runs once immediately**, then loops: `cd`s into the project and runs
   `claude --dangerously-skip-permissions -p "$(cat AGENT_PROMPT.md)"`, teeing
   each session to `/tmp/taskflow-agent-logs/`.
3. **Waits `INTERVAL` (default 30 min) between runs.** On a detected rate limit
   it backs off `RATE_LIMIT_BACKOFF` (default 90 min) instead.
4. **Git-checkpoints every run**, since Claude itself is forbidden from
   committing. Before Claude starts, it checks out (or resumes) a scratch
   branch `taskflow/ticket-<id>` off the base branch (`main`/`master`).
   After Claude's session ends it commits whatever's in the working tree
   there as a WIP snapshot - always, so no work is ever lost to a later
   `git checkout --`/`rm -rf`, regardless of outcome. Then, only if the
   ticket's **database** status (not Claude's process exit code) actually
   came back `completed`, it auto-merges that branch into the base branch
   with a normal local `git merge` - never pushed anywhere. If that merge
   conflicts, it's aborted immediately (`git merge --abort`, base branch
   left untouched) and the ticket is flipped to `blocked`/On Hold with the
   conflicting file list attached, for a human to resolve by hand.
   `blocked` and `rate-limited-paused` tickets are never auto-merged.

```bash
./run-agent.sh            # run now, then every 30 min, forever
./run-agent.sh --once     # a single run then exit (for cron / testing)
./run-agent.sh -i 10      # loop with a custom interval (10 min here)
./run-agent.sh -t 34      # run ONLY TF-034, ignoring priority, then exit
./run-agent.sh -t         # same, but prompts interactively for the ticket number
```

Because it blocks the terminal, run it persistently — e.g.:

```bash
nohup ./run-agent.sh >> /tmp/taskflow-agent-loop.log 2>&1 &   # background
# …or a systemd service, or inside tmux/screen. Stop with Ctrl-C / kill.
```

Tunable via env: `TASKFLOW_INTERVAL`, `TASKFLOW_RATE_BACKOFF`, `TASKFLOW_DIR`,
`TASKFLOW_LOG_DIR`, `TASKFLOW_LOCK_DIR` (per-project lock files, default
`/tmp/taskflow-agent-locks`), `TASKFLOW_SKIP_RETRY` (seconds before retrying
after a claimed ticket's project turned out to be locked by another instance,
default 60). (For cron instead, use `--once` with a `*/30 * * * *` schedule.)

**Running projects in parallel:** start more than one `run-agent.sh` instance
(e.g. a couple of `nohup ./run-agent.sh &` lines, or several systemd service
units) - they share the same lock directory and self-serialize per project
automatically, so up to N instances can each be mid-session on a different
project at once, and none will ever run two sessions in the same
`project_folder` simultaneously.

## License

Personal / self-hosted use. No warranty.
