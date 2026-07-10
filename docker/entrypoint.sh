#!/bin/sh
set -e

# Zapisovatelny adresar pre SQLite databazu (volume /data).
mkdir -p /data
chown -R www-data:www-data /data

# Fallback pre pripad behu bez /data volume.
mkdir -p /var/www/html/data 2>/dev/null || true
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true

exec "$@"
