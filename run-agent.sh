#!/bin/bash
#
# TaskFlow autonomous agent LOOP (host script).
#
# This script IS the scheduler: it runs one headless Claude session immediately,
# then repeats every INTERVAL (default 30 min). After a rate limit it backs off
# longer (default 90 min) before the next attempt. A single-instance lock
# (flock, auto-released on exit) stops two loops from running at once.
#
# Each run first claims a ticket ITSELF, from this shell (mcp-server/
# claim-ticket.js / claim-ticket-by-id.js - the same atomic claim the MCP tool
# uses), so it knows the ticket's project_folder before Claude ever starts. It
# then cd's into that project_folder and boots the Claude Code CLI headlessly
# there, so Claude auto-loads THAT project's own CLAUDE.md/STANDARDS.md as
# context instead of TaskFlow's. The claimed ticket is handed to Claude inline
# in the prompt; Claude does not (and must not) call get_highest_priority_ticket
# itself for that run - see AGENT_PROMPT.md.
#
# Git checkpointing: Claude itself is forbidden (by AGENT_PROMPT.md /
# CLAUDE.md) from running git add/commit/push - changes must stay uncommitted
# for human review. THIS script is what commits, on Claude's behalf, so a run
# has a durable checkpoint instead of relying on an uncommitted working tree
# that a later `rm -rf` or `git checkout --` could lose. Per run: `git init`
# project_folder if it isn't a repo yet, then check out (creating if needed) a
# scratch branch `taskflow/ticket-<id>` and commit a WIP snapshot there at run
# end. Nothing is ever pushed to a remote. See git_checkpoint_* below.
#
# Auto-merge: once a ticket's checkpoint branch holds a session that actually
# COMPLETED (checked against the DB's ai_execution_status, not the process
# exit code - see git_checkpoint_finish), it's merged straight back into the
# base branch with a normal local `git merge`, so main doesn't accumulate an
# ever-growing pile of sibling ticket branches off a stale base. Still no
# `git push` anywhere. If that merge conflicts, it's aborted immediately
# (`git merge --abort`) - base branch is left untouched and the ticket is
# flipped to blocked (On Hold) with the conflicting file list attached, for a
# human to resolve by hand. blocked / rate-limited-paused tickets are never
# merged.
#
# Usage:
#   ./run-agent.sh                 # run now, then loop forever (every 30 min)
#   ./run-agent.sh --once          # a single run, then exit (for cron / testing)
#   ./run-agent.sh -i 10           # loop with a custom interval (10 min here)
#   ./run-agent.sh -t 99            # run ONLY TF-099, ignoring priority, then exit
#   ./run-agent.sh -t                # same, but prompts for the ticket number
#   ./run-agent.sh -h              # show this usage
#
# Run it persistently (it blocks the terminal), e.g.:
#   nohup ./run-agent.sh >> /tmp/taskflow-agent-loop.log 2>&1 &
#   # or a systemd service, or inside tmux/screen
#   # stop it with: Ctrl-C  (or kill the process)
#
# One-time host setup:
#   npm install -g @anthropic-ai/claude-code
#   claude login
#   claude mcp add taskflow-mcp node /absolute/path/to/taskflow/mcp-server/index.js
#   apt install jq   # or: brew install jq  (used to read the claimed ticket JSON
#                     #  and for the rate-limit status check)

# All timestamps in IST, matching the app.
export TZ="${TZ:-Asia/Kolkata}"

# --- Config (env defaults; CLI flags below can override per-invocation) ----
PROJECT_DIR="${TASKFLOW_DIR:-/home/akhil/development/taskflow}"
PROMPT_FILE="$PROJECT_DIR/AGENT_PROMPT.md"
LOG_DIR="${TASKFLOW_LOG_DIR:-/tmp/taskflow-agent-logs}"
LOCK_FILE="${TASKFLOW_LOCK:-/tmp/taskflow_ai.lock}"
INTERVAL="${TASKFLOW_INTERVAL:-1800}"                # 30 min between runs
RATE_LIMIT_BACKOFF="${TASKFLOW_RATE_BACKOFF:-5400}"  # 90 min after a rate limit

print_usage() {
    cat <<'EOF'
Usage: run-agent.sh [options]

  --once            Run a single session then exit (default: loop forever).
  -i, --interval N  Wait N minutes between runs (default: 30, or
                     TASKFLOW_INTERVAL env in seconds).
  -t, --ticket [ID] Run ONLY one ticket, ignoring priority order. Pass the
                     number directly (e.g. `-t 34` for TF-034) to skip the
                     prompt; with no number (`-t`), asks for it interactively.
                     Either way implies a single run (like --once), since
                     it's a one-off action, not part of the unattended loop.
  -h, --help        Show this help.
EOF
}

ONCE=0
TICKET_MODE=0
CLI_INTERVAL_MIN=""
CLI_TICKET_ID=""

while [ $# -gt 0 ]; do
    case "$1" in
        --once)
            ONCE=1
            shift
            ;;
        -i|--interval)
            CLI_INTERVAL_MIN="$2"
            shift 2
            ;;
        -t|--ticket)
            TICKET_MODE=1
            # Optional inline id (e.g. `-t 99`) - only consume $2 if it looks
            # like a whole number (including "0", so it hits the same clear
            # "invalid ticket number" validation below rather than falling
            # through to "unknown option"). `-t` alone (no numeric $2) instead
            # shifts by 1 and the interactive prompt below picks it up.
            if [ -n "${2:-}" ] && [[ "$2" =~ ^[0-9]+$ ]]; then
                CLI_TICKET_ID="$2"
                shift 2
            else
                shift
            fi
            ;;
        -h|--help)
            print_usage
            exit 0
            ;;
        *)
            echo "[run-agent] unknown option: $1" >&2
            print_usage >&2
            exit 1
            ;;
    esac
done

# --- Feature 1: custom wait time via -i/--interval (minutes) ---------------
if [ -n "$CLI_INTERVAL_MIN" ]; then
    if ! [[ "$CLI_INTERVAL_MIN" =~ ^[0-9]+$ ]] || [ "$CLI_INTERVAL_MIN" -le 0 ]; then
        echo "[run-agent] invalid --interval '$CLI_INTERVAL_MIN' - must be a positive whole number of minutes." >&2
        exit 1
    fi
    INTERVAL=$((CLI_INTERVAL_MIN * 60))
fi

# --- Feature 2: -t runs a specific ticket, id inline or prompted -----------
FORCED_TICKET_ID=""
if [ "$TICKET_MODE" -eq 1 ]; then
    if [ -n "$CLI_TICKET_ID" ]; then
        # Id was given on the command line (`-t 99`) - use it, no prompt.
        FORCED_TICKET_ID="$CLI_TICKET_ID"
    else
        # No id given (`-t` alone) - ask for it interactively.
        read -rp "Enter ticket number (e.g. 34 for TF-034): " ticket_input
        if ! [[ "$ticket_input" =~ ^[0-9]+$ ]] || [ "$ticket_input" -le 0 ]; then
            echo "[run-agent] invalid ticket number '$ticket_input' - enter digits only, e.g. 34 for TF-034. Stopping." >&2
            exit 1
        fi
        FORCED_TICKET_ID="$ticket_input"
    fi
    ONCE=1   # a forced single-ticket run is a one-off action, not a loop.
fi

# --- Single-instance lock (fd held for the loop's lifetime) ----------------
# flock releases automatically when the process exits, so there is no stale
# lock to clean up even after a crash or kill.
exec 9>"$LOCK_FILE" || { echo "[run-agent] cannot open lock $LOCK_FILE"; exit 1; }
if ! flock -n 9; then
    echo "[run-agent] another run-agent loop is already running. Exiting."
    exit 0
fi

# Clean shutdown on Ctrl-C / termination (interrupts sleep too).
trap 'echo "[run-agent] stopping."; exit 0' INT TERM

# --- Git checkpointing (init + per-run WIP commit to a scratch branch) -----
#
# Picks the branch a new scratch branch should fork from: the branch already
# checked out, unless that's itself a leftover taskflow/ticket-* branch from
# a prior run (e.g. this project_folder is shared across tickets), in which
# case fall back to main/master/whatever local branch isn't a scratch branch.
resolve_base_branch() {
    local dir="$1" cur
    cur="$(git -C "$dir" symbolic-ref --short HEAD 2>/dev/null)"
    if [ -n "$cur" ] && [[ "$cur" != taskflow/ticket-* ]]; then
        echo "$cur"
        return
    fi
    if git -C "$dir" show-ref --verify --quiet refs/heads/main; then
        echo main
    elif git -C "$dir" show-ref --verify --quiet refs/heads/master; then
        echo master
    else
        git -C "$dir" for-each-ref --format='%(refname:short)' refs/heads/ \
            | grep -v '^taskflow/ticket-' | head -n1
    fi
}

# Runs BEFORE Claude starts. Ensures project_folder is a git repo and checks
# out this ticket's scratch branch - reusing it (with its prior checkpoint
# commits) if a previous run already created it, so resume picks up exactly
# where the last session left off.
git_checkpoint_start() {
    local dir="$1" task_id="$2" log="$3"
    local scratch_branch="taskflow/ticket-$task_id"

    if [ ! -d "$dir/.git" ]; then
        echo "[run-agent] $dir is not a git repo; running git init." | tee -a "$log"
        git -C "$dir" init -q
    fi

    if ! git -C "$dir" rev-parse HEAD >/dev/null 2>&1; then
        git -C "$dir" add -A
        git -C "$dir" commit -q -m "chore: baseline commit for TaskFlow checkpoints" --allow-empty
    fi

    if git -C "$dir" show-ref --verify --quiet "refs/heads/$scratch_branch"; then
        echo "[run-agent] resuming git checkpoint branch $scratch_branch" | tee -a "$log"
        git -C "$dir" checkout -q "$scratch_branch"
    else
        local base_branch
        base_branch="$(resolve_base_branch "$dir")"
        echo "[run-agent] creating git checkpoint branch $scratch_branch from ${base_branch:-HEAD}" | tee -a "$log"
        [ -n "$base_branch" ] && git -C "$dir" checkout -q "$base_branch"
        git -C "$dir" checkout -q -b "$scratch_branch"
    fi
}

# Runs AFTER Claude's session ends (success, blocked, or rate-limited - always,
# so no work is ever lost). Commits whatever Claude left in the working tree
# as a WIP checkpoint on the scratch branch. No-ops cleanly if there's nothing
# to commit.
git_checkpoint_commit() {
    local dir="$1" task_id="$2" run_id="$3" code="$4" log="$5"
    git -C "$dir" add -A
    if git -C "$dir" diff --cached --quiet; then
        echo "[run-agent] no changes to checkpoint for TF-$task_id." | tee -a "$log"
        return
    fi
    git -C "$dir" commit -q -m "WIP checkpoint: TF-$task_id (run ${run_id:-none}, exit $code)"
    echo "[run-agent] committed WIP checkpoint to $(git -C "$dir" branch --show-current)." | tee -a "$log"
}

# Runs AFTER the checkpoint commit. A rate-limited run (75) expects to be
# resumed, so leave the scratch branch checked out for next time - no status
# check needed, since a session that hit 75 never got to call
# update_ticket_status at all. Otherwise, switch project_folder back to its
# base branch, then - ONLY for a ticket whose DB status is actually
# 'completed' - auto-merge the scratch branch into it. The exit code `code`
# is deliberately NOT used to decide "did it complete": a session can exit 0
# after already calling update_ticket_status('blocked') mid-session, or crash
# non-zero while the ticket sits unreconciled at 'in-progress'. Only the DB
# row (read fresh, after Claude's session has fully ended) is authoritative -
# see getTicketState in mcp-server/db.js.
git_checkpoint_finish() {
    local dir="$1" task_id="$2" code="$3" log="$4"
    [ "$code" -eq 75 ] && return
    local base_branch
    base_branch="$(resolve_base_branch "$dir")"
    [ -z "$base_branch" ] && return
    git -C "$dir" checkout -q "$base_branch" 2>>"$log"

    local scratch_branch="taskflow/ticket-$task_id"
    local state ai_status
    state="$(node "$PROJECT_DIR/mcp-server/get-ticket-status.js" "$task_id" 2>>"$log")"
    ai_status="$(echo "$state" | jq -r '.ai_execution_status // empty' 2>/dev/null)"

    if [ "$ai_status" != "completed" ]; then
        echo "[run-agent] TF-$task_id final status is '${ai_status:-unknown}' (not completed); leaving $scratch_branch unmerged." | tee -a "$log"
        return
    fi

    echo "[run-agent] TF-$task_id completed; auto-merging $scratch_branch into $base_branch…" | tee -a "$log"
    if git -C "$dir" merge -q -m "Merge $scratch_branch into $base_branch (TF-$task_id)" "$scratch_branch" >>"$log" 2>&1; then
        echo "[run-agent] merge clean: $scratch_branch -> $base_branch." | tee -a "$log"
        node "$PROJECT_DIR/mcp-server/record-merge.js" "$task_id" "$scratch_branch" "$base_branch" 2>&1 | tee -a "$log"
    else
        local conflicts
        conflicts="$(git -C "$dir" diff --name-only --diff-filter=U)"
        git -C "$dir" merge --abort 2>>"$log"
        echo "[run-agent] merge CONFLICT: $scratch_branch -> $base_branch; aborted, $base_branch left untouched. Conflicting files: ${conflicts:-(none captured)}" | tee -a "$log"
        node "$PROJECT_DIR/mcp-server/block-ticket.js" "$task_id" \
            "Auto-merge of $scratch_branch into $base_branch conflicted and was aborted (git merge --abort); $base_branch left untouched. Conflicting files:
$conflicts" \
            "post-run merge" \
            2>&1 | tee -a "$log"
    fi
}

# --- One headless run; prints status and returns Claude's exit code --------
#   0  = success / nothing to do
#   75 = rate-limited (triggers the longer backoff)
#   *  = other failure
run_once() {
    cd "$PROJECT_DIR" || { echo "[run-agent] cannot cd to $PROJECT_DIR"; return 1; }
    mkdir -p "$LOG_DIR"
    local run_log="$LOG_DIR/run-$(date +%Y%m%d-%H%M%S).log"

    # Recover any ticket a previous session left stuck in-progress (e.g. killed
    # by a token limit before it could self-report). Safe here: no session is
    # running yet this iteration.
    echo "[run-agent] reconciling orphaned sessions…" | tee "$run_log"
    node "$PROJECT_DIR/mcp-server/reconcile.js" 2>&1 | tee -a "$run_log" || echo "[run-agent] reconcile skipped (error)" | tee -a "$run_log"

    # Claim the ticket from THIS shell, before Claude starts. CLAUDE.md is only
    # auto-loaded at process start, so the harness - not the agent - has to be
    # the one that knows the project_folder and cd's there first.
    local ticket_json
    if [ -n "$FORCED_TICKET_ID" ]; then
        local forced_key
        forced_key="TF-$(printf '%03d' "$FORCED_TICKET_ID")"
        echo "[run-agent] claiming ticket $forced_key (forced via -t)…" | tee -a "$run_log"
        # Stream stderr to BOTH the terminal and the log (not just the log) -
        # this is an interactive, one-off command, so a claim failure needs to
        # be visible on screen immediately, not only discoverable in the log.
        ticket_json="$(node "$PROJECT_DIR/mcp-server/claim-ticket-by-id.js" "$FORCED_TICKET_ID" \
            2> >(tee -a "$run_log" >&2))"
        if [ -z "$ticket_json" ]; then
            echo "[run-agent] could not claim $forced_key (see message above). Stopping." | tee -a "$run_log"
            return 1
        fi
    else
        echo "[run-agent] claiming highest-priority ticket…" | tee -a "$run_log"
        ticket_json="$(node "$PROJECT_DIR/mcp-server/claim-ticket.js" 2>>"$run_log")"
        if [ -z "$ticket_json" ]; then
            echo "[run-agent] claim step produced no output (see log above); nothing to do this cycle." | tee -a "$run_log"
            return 1
        fi
        if [ "$ticket_json" = "{}" ]; then
            echo "[run-agent] no pending tickets." | tee -a "$run_log"
            return 0
        fi
    fi

    local task_id project_id project_folder
    task_id="$(echo "$ticket_json" | jq -r '.id')"
    project_id="$(echo "$ticket_json" | jq -r '.project_id // empty')"
    project_folder="$(echo "$ticket_json" | jq -r '.project_folder // empty')"
    echo "[run-agent] claimed TF-$task_id -> project_folder: ${project_folder:-'(not set)'}" | tee -a "$run_log"

    if [ -z "$project_folder" ] || [ ! -d "$project_folder" ]; then
        echo "[run-agent] project_folder is unset or missing on this host; marking blocked." | tee -a "$run_log"
        node "$PROJECT_DIR/mcp-server/block-ticket.js" "$task_id" \
            "project_folder ('${project_folder:-null}') is not set or does not exist on this host; run-agent.sh could not establish a working directory." \
            2>&1 | tee -a "$run_log"
        return 1
    fi

    # Ensure project_folder is a git repo and check out this ticket's scratch
    # checkpoint branch before Claude ever starts - see git_checkpoint_* above.
    git_checkpoint_start "$project_folder" "$task_id" "$run_log"

    # Static procedure + the ticket already claimed above, so Claude uses it
    # instead of calling get_highest_priority_ticket (which would claim a
    # second, unrelated ticket).
    local full_prompt
    full_prompt="$(cat "$PROMPT_FILE")

---
CLAIMED TICKET (already locked by run-agent.sh; do NOT call
get_highest_priority_ticket for this run - use this ticket):
$ticket_json"

    local started_at
    started_at="$(date -u '+%Y-%m-%dT%H:%M:%S')"

    # Create the `runs` row NOW (before Claude starts) and export its id so
    # the MCP server subprocess (inherits this shell's env) tags every
    # mcp_invocations/ai_session_logs row it writes this session with it -
    # see start-run.js and db.js's RUN_ID. record-run.js below UPDATEs this
    # same row once the session ends.
    local run_id
    run_id="$(node "$PROJECT_DIR/mcp-server/start-run.js" "$task_id" "${project_id:--}" 2>>"$run_log")"
    if [ -n "$run_id" ]; then
        export TASKFLOW_RUN_ID="$run_id"
        echo "[run-agent] run_id=$run_id" | tee -a "$run_log"
    else
        echo "[run-agent] start-run.js produced no run_id (see log above); invocations this session will be untagged." | tee -a "$run_log"
        unset TASKFLOW_RUN_ID
    fi

    echo "[run-agent] $(date '+%Y-%m-%d %H:%M:%S %Z') starting session in $project_folder -> $run_log" | tee -a "$run_log"
    # Plain `-p`/`--verbose` TEXT mode does NOT stream per-turn output - the
    # CLI buffers the whole session and prints one summary line at the end
    # (verified empirically, see mcp-server/format-stream.js header comment).
    # --output-format stream-json is the only mode that emits each turn (
    # thoughts, tool calls, tool results) live as it happens. The raw JSONL
    # is teed to $run_log FIRST (so it's the source of truth - including for
    # the rate-limit check below), then piped through format-stream.js for a
    # readable live transcript on the terminal.
    ( cd "$project_folder" && claude --dangerously-skip-permissions --verbose \
        --output-format stream-json -p "$full_prompt" ) 2>&1 \
        | tee -a "$run_log" \
        | node "$PROJECT_DIR/mcp-server/format-stream.js"
    local code=${PIPESTATUS[0]}   # claude's exit code (first stage), not tee's/node's

    # Rate-limit detection: a "rate_limit_event" line appears in EVERY session
    # as routine telemetry, and rate_limit_info.status can legitimately be
    # "allowed" OR "allowed_warning" (e.g. "90% of your 5-hour quota used") on
    # a run that completed successfully - neither means the run was actually
    # blocked. Only a status that does NOT start with "allowed" (e.g.
    # "rejected") is a genuine hit; checking with startswith avoids the false
    # positive that a bare "!= allowed" comparison would produce on every
    # single warning (which was wrongly triggering the 90-min backoff always).
    structured_hit=$(jq -R 'fromjson? // empty' "$run_log" 2>/dev/null \
        | jq -s 'any(.[]; .type=="rate_limit_event"
                      and ((.rate_limit_info.status // "allowed")
                           | ascii_downcase | startswith("allowed") | not))' \
              2>/dev/null)
    if [ "$structured_hit" = "true" ] || \
       grep -qiE 'usage limit reached|rate limit exceeded|too many requests|\b429\b' "$run_log"; then
        echo "[run-agent] rate limit detected; will back off."
        code=75
    fi

    # Checkpoint whatever Claude left in the working tree - success, blocked,
    # or rate-limited, always, so no session's work is ever lost to a later
    # `git checkout --`/`rm -rf` - then, unless we expect to resume (75),
    # return project_folder to its base branch and auto-merge if (and only
    # if) the ticket's DB status actually completed. See git_checkpoint_* above.
    git_checkpoint_commit "$project_folder" "$task_id" "$run_id" "$code" "$run_log"
    git_checkpoint_finish "$project_folder" "$task_id" "$code" "$run_log"

    # Parse the stream-json log's terminal `result` event for token usage/cost
    # and update the `runs` row start-run.js created above (best-effort - see
    # record-run.js's header comment; never blocks or fails this iteration).
    node "$PROJECT_DIR/mcp-server/record-run.js" "${run_id:--}" "$task_id" "${project_id:--}" "$run_log" "$code" "$started_at" \
        2>&1 | tee -a "$run_log"

    echo "[run-agent] $(date '+%Y-%m-%d %H:%M:%S %Z') finished with code $code"
    return $code
}

# --- Main: run immediately, then loop --------------------------------------
if [ -n "$FORCED_TICKET_ID" ]; then
    echo "[run-agent] forced single run for TF-$(printf '%03d' "$FORCED_TICKET_ID")."
else
    echo "[run-agent] loop started (interval ${INTERVAL}s, rate-limit backoff ${RATE_LIMIT_BACKOFF}s)."
fi
while true; do
    run_once
    code=$?

    [ "$ONCE" -eq 1 ] && exit "$code"

    if [ "$code" -eq 75 ]; then
        wait_s="$RATE_LIMIT_BACKOFF"
    else
        wait_s="$INTERVAL"
    fi
    next=$(date -d "+${wait_s} seconds" '+%Y-%m-%d %H:%M:%S %Z' 2>/dev/null || echo "in ${wait_s}s")
    echo "[run-agent] next run at ${next}"
    sleep "$wait_s"
done
