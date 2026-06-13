#!/usr/bin/env bash
# App container entrypoint (Plan §26.1). Idempotent first-run setup, then exec
# the service command (php-fpm / queue:work / schedule:work).
set -euo pipefail

cd /var/www/html

# Dev convenience: ensure a .env exists. Never overwrites an existing one, and
# never writes secrets (placeholders come from .env.example).
if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Populate dependencies if the vendor volume is empty (first boot).
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --no-progress --prefer-dist
fi

# Generate an application key if one is not set yet.
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force --ansi || true
fi

exec "$@"
