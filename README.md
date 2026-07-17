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
| Backend    | Vanilla PHP 8.2 (single `api.php` action router)     |
| Database   | MySQL 8 (external, shared)                            |
| Frontend   | HTML5, vanilla JS, Tailwind (CDN), SortableJS, Quill  |
| Runtime    | Docker Compose (PHP + Apache container)               |
| Auth       | None (local, single-user)                            |

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

## License

Personal / self-hosted use. No warranty.
