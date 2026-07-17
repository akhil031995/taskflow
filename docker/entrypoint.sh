#!/usr/bin/env bash
# TaskFlow container entrypoint.
# Runs database migrations (creating the DB if needed) before starting Apache.
# Migrations therefore run automatically on every container start / restart.
set -e

echo "[taskflow] bootstrapping…"

# --wait retries the connection while the (external) MySQL server comes up.
php /var/www/html/config/migrate.php --wait

echo "[taskflow] starting Apache."
# Hand off to the image's default command (apache2-foreground).
exec "$@"
