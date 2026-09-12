#!/usr/bin/env bash
# Boot the app for end to end tests against a throwaway database.
set -euo pipefail

PORT="${1:-8011}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB="$ROOT/database/e2e.sqlite"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
# Rate limits and password rules that help in production get in the way of
# a test signing in over and over.
export APP_ENV=local

rm -f "$DB"
touch "$DB"

php artisan migrate:fresh --seed --no-interaction --quiet
exec php artisan serve --port="$PORT" --no-reload
