#!/usr/bin/env bash
# TaskFlow container entrypoint.
# Runs database migrations (creating the DB if needed) before starting Apache,
# so migrations apply automatically on every container start / restart.
set -e

echo "[taskflow] running database migrations…"
php /var/www/html/config/migrate.php --wait
echo "[taskflow] migrations done; starting Apache."

# Hand off to the image's default command (apache2-foreground on port 80).
exec "$@"
