#!/usr/bin/env bash
# Run ON THE HOSTINGER SERVER (over SSH) every time you deploy a new version of
# the backend. See docs/DEPLOY-HOSTINGER.md, step 12.
#
#   bash ~/loans/deploy/server-update.sh
#
# Pulls the latest code from GitHub, installs PHP dependencies, runs any new
# database migrations and rebuilds Laravel's caches. Safe to run repeatedly.
# The web-app files you uploaded into backend/public are ignored by git and
# are left in place.

set -euo pipefail

# If the default "php" on the server is older than 8.3, point this at the 8.3 binary:
#   PHP_BIN=/opt/alt/php83/usr/bin/php bash ~/loans/deploy/server-update.sh
PHP="${PHP_BIN:-php}"

APP_DIR="${APP_DIR:-$HOME/loans}"
cd "$APP_DIR"

echo "==> Pulling the latest code"
git pull --ff-only origin main

cd backend

COMPOSER="composer"
if command -v composer2 >/dev/null 2>&1; then COMPOSER="composer2"; fi

echo "==> Installing PHP dependencies"
"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-scripts
"$PHP" artisan package:discover --ansi

echo "==> Putting the site in maintenance mode while the database is updated"
"$PHP" artisan down --retry=30 || true

# Bring the site back up even if a step below fails.
trap '"$PHP" artisan up >/dev/null 2>&1 || true' EXIT

echo "==> Running database migrations"
"$PHP" artisan migrate --force

echo "==> Rebuilding caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

"$PHP" artisan up
trap - EXIT

echo "==> Done. The backend is up to date."
echo "    If the web app changed too, upload a new deploy/out/web-app.zip (guide, step 9)."
