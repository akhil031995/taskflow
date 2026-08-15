#!/usr/bin/env node
/**
 * Formats Claude Code's `--output-format stream-json` event stream into a
 * readable, live transcript for the terminal (and run-agent.sh's log file).
 *
 * Plain `-p`/`--verbose` text mode does NOT stream per-turn output - the CLI
 * buffers the whole session and prints one final summary line at the end
 * (verified empirically). stream-json is the only mode that emits one JSON
 * event per turn as it happens; this script turns that into human-readable
 * lines, printed as each event arrives (no internal buffering here - stdout
 * writes are flushed per line).
 *
 * Usage: claude ... --output-format stream-json | node format-stream.js
 */
import readline from 'node:readline';

const rl = readline.createInterface({ input: process.stdin, terminal: false });

function ts() {
  return new Date().toTimeString().slice(0, 8);
}

function trunc(value, max = 400) {
  const s = typeof value === 'string' ? value : JSON.stringify(value);
  return s.length > max ? s.slice(0, max) + '…' : s;
}

rl.on('line', (raw) => {
  const line = raw.trim();
  if (!line) return;

  let ev;
  try {
    ev = JSON.parse(line);
  } catch {
    // Not a JSON event (e.g. a stray stderr line merged in) - show it as-is
    // rather than silently swallowing something that might be an error.
    console.log(`[${ts()}] ! ${line}`);
    return;
  }

  switch (ev.type) {
    case 'assistant': {
      for (const c of ev.message?.content || []) {
        if (c.type === 'text' && c.text?.trim()) {
          console.log(`[${ts()}] 🤖 ${c.text.trim()}`);
        } else if (c.type === 'thinking' && c.thinking?.trim()) {
          console.log(`[${ts()}] 💭 ${trunc(c.thinking.trim(), 300)}`);
        } else if (c.type === 'tool_use') {
          console.log(`[${ts()}] → ${c.name}(${trunc(c.input || {}, 200)})`);
        }
      }
      break;
    }
    case 'user': {
      for (const c of ev.message?.content || []) {
        if (c.type === 'tool_result') {
          console.log(`[${ts()}] ← ${trunc(c.content, 300)}`);
        }
      }
      break;
    }
    case 'rate_limit_event': {
      const info = ev.rate_limit_info || {};
      if (String(info.status).toLowerCase() !== 'allowed') {
        console.log(`[${ts()}] ⚠ rate limit: ${trunc(info, 200)}`);
      }
      break;
    }
    case 'result': {
      const outcome = ev.is_error ? 'ERROR' : 'ok';
      console.log(
        `[${ts()}] — session finished (${outcome}, ${ev.num_turns ?? '?'} turns, ${ev.duration_api_ms ?? '?'}ms)`
      );
      break;
    }
    default:
      // system/init, thinking_tokens, etc. - internal bookkeeping, not
      // conversation content. Skipped to keep the transcript readable.
      break;
  }
});
