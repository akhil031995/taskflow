#!/usr/bin/env node
/**
 * Token-reduction report: retrieval (taskflow-repomap) vs blind-scan baseline.
 *
 * TF-27's premise is that a run which locates code via search_symbols /
 * search_code / get_outline instead of reading whole files should burn far
 * fewer tokens loading file content into context. cache_creation_tokens is
 * the proxy for that - it's the token cost of content newly added to the
 * context window each turn (file reads, tool results, etc.), unlike
 * input_tokens which mysql2's usage snapshot only reports for the last turn.
 *
 * Splits finished runs (outcome IS NOT NULL with cache_creation_tokens > 0,
 * i.e. record-run.js reached the terminal `result` event AND the session
 * actually did work - excludes crashed/orphaned sessions that died before
 * their first turn) into two groups by whether any repomap tool call is logged
 * against that run_id in mcp_invocations:
 *   - retrieval : >=1 search_symbols/search_code/get_outline/refresh_index call
 *   - baseline  : 0 such calls (pre-dependency runs, or a run that ignored
 *                 CLAUDE.md's navigation section and read files directly)
 *
 * Reports mean cache_creation_tokens per turn (normalizes away runs that
 * simply did more work) for each group, plus the raw per-run breakdown.
 *
 * Run standalone: node mcp-server/token-report.js
 */
import { pool } from './db.js';

const REPOMAP_TOOLS = ['search_symbols', 'search_code', 'get_outline', 'refresh_index'];

function fmt(n) {
  return n === null || n === undefined ? 'n/a' : Math.round(n).toLocaleString();
}

async function main() {
  const [runs] = await pool.query(
    `SELECT id, task_id, project_id, num_turns, cache_creation_tokens, cache_read_tokens,
            input_tokens, output_tokens, outcome
       FROM runs
      WHERE outcome IS NOT NULL AND num_turns > 0 AND cache_creation_tokens > 0
      ORDER BY id`
  );

  if (runs.length === 0) {
    console.log('[token-report] no finished runs with usage data yet.');
    await pool.end();
    return;
  }

  const [invRows] = await pool.query(
    `SELECT run_id, tool, COUNT(*) AS c
       FROM mcp_invocations
      WHERE run_id IS NOT NULL AND tool IN (?)
      GROUP BY run_id, tool`,
    [REPOMAP_TOOLS]
  );
  const repomapCallsByRun = new Map();
  for (const r of invRows) {
    repomapCallsByRun.set(r.run_id, (repomapCallsByRun.get(r.run_id) || 0) + r.c);
  }

  const groups = { retrieval: [], baseline: [] };
  for (const run of runs) {
    const repomapCalls = repomapCallsByRun.get(run.id) || 0;
    const perTurn = run.cache_creation_tokens / run.num_turns;
    (repomapCalls > 0 ? groups.retrieval : groups.baseline).push({ ...run, repomapCalls, perTurn });
  }

  function summarize(rows) {
    if (rows.length === 0) return null;
    const mean = (key) => rows.reduce((s, r) => s + r[key], 0) / rows.length;
    return { count: rows.length, meanPerTurn: mean('perTurn'), meanTurns: mean('num_turns') };
  }

  const retrievalSummary = summarize(groups.retrieval);
  const baselineSummary = summarize(groups.baseline);

  console.log('[token-report] cache_creation_tokens per turn, by run:\n');
  for (const [label, rows] of Object.entries(groups)) {
    for (const r of rows.sort((a, b) => a.id - b.id)) {
      console.log(
        `  run ${r.id} (task ${r.task_id}, ${label}, ${r.repomapCalls} repomap call(s)): ` +
          `${fmt(r.perTurn)} tokens/turn over ${r.num_turns} turns [${r.outcome}]`
      );
    }
  }

  console.log('\n[token-report] summary:');
  console.log(
    `  baseline  (0 repomap calls): ${baselineSummary ? `n=${baselineSummary.count}, mean ${fmt(baselineSummary.meanPerTurn)} tokens/turn` : 'no runs yet'}`
  );
  console.log(
    `  retrieval (>=1 repomap call): ${retrievalSummary ? `n=${retrievalSummary.count}, mean ${fmt(retrievalSummary.meanPerTurn)} tokens/turn` : 'no runs yet'}`
  );

  if (baselineSummary && retrievalSummary && baselineSummary.meanPerTurn > 0) {
    const reduction = 100 * (1 - retrievalSummary.meanPerTurn / baselineSummary.meanPerTurn);
    console.log(`  → retrieval uses ${reduction.toFixed(1)}% ${reduction >= 0 ? 'fewer' : 'more'} tokens/turn than baseline.`);
  } else {
    console.log('  → need at least one run in each group to compute a reduction figure.');
  }

  await pool.end();
}

main().catch((e) => {
  console.error('[token-report] error:', e.message);
  process.exit(1);
});
