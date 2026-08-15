#!/usr/bin/env node
/**
 * Log a clean auto-merge of a completed ticket's checkpoint branch into the
 * base branch (see recordMerge in db.js). Called by run-agent.sh's
 * git_checkpoint_finish right after `git merge` succeeds with no conflicts.
 * The conflict path does NOT use this script - it calls block-ticket.js
 * instead, since a conflict blocks the ticket rather than merely logging.
 *
 * Usage: node record-merge.js <task_id> <scratch_branch> <base_branch>
 */
import { pool, recordMerge } from './db.js';

const [, , taskIdArg, scratchBranch, baseBranch] = process.argv;
const taskId = Number(taskIdArg);

async function main() {
  if (!taskId || !scratchBranch || !baseBranch) {
    console.error('[record-merge] usage: node record-merge.js <task_id> <scratch_branch> <base_branch>');
    process.exit(1);
  }
  await recordMerge(taskId, scratchBranch, baseBranch);
  console.error(`[record-merge] TF-${taskId}: logged merge of ${scratchBranch} into ${baseBranch}.`);
  await pool.end();
}

main().catch((e) => {
  console.error('[record-merge] error:', e.message);
  process.exit(1);
});
