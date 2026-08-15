<?php
/**
 * Database connection (PDO/MySQL).
 *
 * Credentials come from environment variables injected by docker-compose,
 * with sensible defaults for a local `docker compose up`.
 *
 *   db()        -> shared connection to the application database (DB_NAME)
 *   db_server() -> connection to the MySQL server WITHOUT selecting a database,
 *                  used by the migrator to `CREATE DATABASE IF NOT EXISTS`.
 */

declare(strict_types=1);

/** PDO options shared by both connection helpers. */
function db_options(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Pin the session to IST (+05:30). TIMESTAMP columns are stored UTC and
        // converted to this zone on read, and NOW()/CURRENT_TIMESTAMP write it,
        // so every time value the app sees is IST.
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'",
    ];
}

/** Connection to the application database (creates it lazily is NOT done here - see migrate.php). */
function db(): PDO
{
    // Reuse a single connection per request.
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'taskflow';
    $user = getenv('DB_USER') ?: 'taskflow';
    $pass = getenv('DB_PASS') ?: 'taskflow_secret';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, db_options());

    return $pdo;
}

/**
 * Connection to the MySQL server with no database selected.
 * Used before the app database is guaranteed to exist.
 */
function db_server(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'taskflow';
    $pass = getenv('DB_PASS') ?: 'taskflow_secret';

    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, db_options());
}
