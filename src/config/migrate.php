<?php
/**
 * Self-bootstrapping migration runner.
 *
 * Responsibilities:
 *   1. Create the application database if it does not exist.
 *   2. Track applied migrations in a `schema_migrations` table.
 *   3. Apply any pending `*.sql` files from src/migrations/ in filename order.
 *
 * Usage:
 *   - CLI (container start): `php config/migrate.php --wait`
 *       `--wait` retries the DB connection for up to 60s (external MySQL may
 *       still be starting up).
 *   - Programmatic: `require`, then call `run_migrations()`.
 *
 * Migrations are idempotent: schema files use `IF NOT EXISTS`, the version
 * ledger uses `INSERT IGNORE`, and each file runs at most once.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Directory holding numbered *.sql migration files. */
function migrations_dir(): string
{
    return __DIR__ . '/../migrations';
}

/** Create the configured database if it is missing. */
function ensure_database_exists(): void
{
    $name = getenv('DB_NAME') ?: 'taskflow';
    // Guard against injection via a malformed DB name (can't bind identifiers).
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Invalid DB_NAME: {$name}");
    }
    db_server()->exec(
        "CREATE DATABASE IF NOT EXISTS `{$name}`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}

/** Ensure the migration ledger table exists. */
function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Split a .sql file into individual statements.
 * Migration files are plain DDL/DML (no stored routines / no `;` inside
 * values), so splitting on `;` after stripping `--` comments is safe.
 */
function split_sql(string $sql): array
{
    $lines = preg_split('/\r?\n/', $sql) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '--')) {
            continue; // drop comment lines
        }
        $kept[] = $line;
    }
    $joined = implode("\n", $kept);
    $parts = array_map('trim', explode(';', $joined));
    return array_values(array_filter($parts, static fn ($s) => $s !== ''));
}

/**
 * Apply all pending migrations.
 * @return string[] versions that were applied this run.
 */
function run_migrations(bool $verbose = false): array
{
    ensure_database_exists();

    $pdo = db();
    ensure_migrations_table($pdo);

    $applied = array_flip(
        $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
    );

    $files = glob(migrations_dir() . '/*.sql') ?: [];
    sort($files); // filename order = execution order (001_, 002_, …)

    $ran = [];
    $record = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');

    foreach ($files as $file) {
        $version = basename($file);
        if (isset($applied[$version])) {
            continue; // already applied
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read migration: {$version}");
        }

        foreach (split_sql($sql) as $statement) {
            $pdo->exec($statement);
        }
        $record->execute([$version]);
        $ran[] = $version;

        if ($verbose) {
            fwrite(STDOUT, "  ✓ applied {$version}\n");
        }
    }

    if ($verbose && $ran === []) {
        fwrite(STDOUT, "  · no pending migrations\n");
    }
    return $ran;
}

// -------------------------------------------------------------------
// CLI entry point (used by the container entrypoint).
// -------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $wait     = in_array('--wait', $argv, true);
    $deadline = time() + 60;

    // Wait for the (possibly external) MySQL server to accept connections.
    while (true) {
        try {
            db_server();
            break;
        } catch (Throwable $e) {
            if (!$wait || time() >= $deadline) {
                fwrite(STDERR, "Database not reachable: {$e->getMessage()}\n");
                exit(1);
            }
            fwrite(STDOUT, "Waiting for database…\n");
            sleep(2);
        }
    }

    try {
        fwrite(STDOUT, "Running migrations…\n");
        run_migrations(true);
        fwrite(STDOUT, "Migrations complete.\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
        exit(1);
    }
}
