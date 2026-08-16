// Stack detection: inspect a project folder for package.json / composer.json
// / Dockerfile. Pure filesystem, no DB dependency - shared by
// bootstrap-standards.js (one-time standards enrollment) and
// project-primer.js (primer's "Stack" section) so neither has to import the
// other (importing bootstrap-standards.js directly would also pull in its
// top-level main() invocation and its `db.js`/pool dependency).
import fs from 'node:fs';
import { join } from 'node:path';

/** Read and JSON.parse a file, or return null if missing/invalid. */
function readJson(path) {
  if (!fs.existsSync(path)) return null;
  try {
    return JSON.parse(fs.readFileSync(path, 'utf8'));
  } catch {
    return null;
  }
}

/** Inspect a project's folder for package.json / composer.json / Dockerfile. */
export function detectStack(projectFolder) {
  const facts = { node: null, php: null, docker: null };

  const pkg = readJson(join(projectFolder, 'package.json'));
  if (pkg) {
    const deps = Object.keys(pkg.dependencies || {});
    facts.node = {
      name: pkg.name || null,
      deps: deps.slice(0, 8),
      hasTests: Boolean(pkg.scripts && pkg.scripts.test && !/no test specified/i.test(pkg.scripts.test)),
    };
  }

  const composer = readJson(join(projectFolder, 'composer.json'));
  if (composer) {
    facts.php = {
      name: composer.name || null,
      require: Object.keys(composer.require || {}).filter((k) => k !== 'php'),
      phpVersion: (composer.require || {}).php || null,
    };
  }

  const dockerfilePath = join(projectFolder, 'Dockerfile');
  if (fs.existsSync(dockerfilePath)) {
    const contents = fs.readFileSync(dockerfilePath, 'utf8');
    const fromLine = contents.split(/\r?\n/).find((l) => /^\s*FROM\s+\S+/i.test(l));
    facts.docker = fromLine ? fromLine.trim().replace(/^FROM\s+/i, '') : 'Dockerfile present';
  }

  return facts;
}

/** Render the detected facts as a markdown bullet list, or null if nothing was found. */
export function describeStack(facts) {
  const lines = [];
  if (facts.node) {
    lines.push(`- Node.js project${facts.node.name ? ` (\`${facts.node.name}\`)` : ''}, from \`package.json\`.`);
    if (facts.node.deps.length) lines.push(`  Key dependencies: ${facts.node.deps.map((d) => `\`${d}\``).join(', ')}.`);
    lines.push(`  ${facts.node.hasTests ? 'Has a `test` script - run it before marking a ticket complete.' : 'No `test` script detected.'}`);
  }
  if (facts.php) {
    lines.push(`- PHP project${facts.php.name ? ` (\`${facts.php.name}\`)` : ''}, from \`composer.json\`${facts.php.phpVersion ? ` (requires PHP ${facts.php.phpVersion})` : ''}.`);
    if (facts.php.require.length) lines.push(`  Key dependencies: ${facts.php.require.map((d) => `\`${d}\``).join(', ')}.`);
  }
  if (facts.docker) {
    lines.push(`- Containerized: \`Dockerfile\` present (base image \`${facts.docker}\`).`);
  }
  return lines.length ? lines.join('\n') : null;
}
