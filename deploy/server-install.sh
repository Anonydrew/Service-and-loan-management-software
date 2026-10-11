#!/usr/bin/env bash
# Run ONCE on the Hostinger server (over SSH), after cloning the repository and
# filling in backend/.env. See docs/DEPLOY-HOSTINGER.md, step 7.
#
#   bash ~/loans/deploy/server-install.sh
#
# If the server's default php is older than 8.3:
#   PHP_BIN=/opt/alt/php83/usr/bin/php bash ~/loans/deploy/server-install.sh

set -euo pipefail

PHP="${PHP_BIN:-php}"
APP_DIR="${APP_DIR:-$HOME/loans}"
cd "$APP_DIR/backend"

if ! "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
  echo "PHP 8.3+ is required, but '$PHP' is $("$PHP" -r 'echo PHP_VERSION;')."
  echo "Re-run with PHP_BIN=/opt/alt/php83/usr/bin/php (see the guide, step 6)."
  exit 1
fi

for ext in bcmath ctype curl dom fileinfo gd intl mbstring openssl pdo_mysql tokenizer xml zip; do
  "$PHP" -m | grep -qi "^$ext$" || { echo "Missing PHP extension: $ext. Enable it in hPanel > Advanced > PHP Configuration."; exit 1; }
done

if [ ! -f .env ]; then
  echo "backend/.env is missing. Copy .env.production.example to .env and fill it in first."
  exit 1
fi
if grep -vE '^[[:space:]]*#' .env | grep -qE '<[^>]+>'; then
  echo "backend/.env still contains <placeholders>:"
  grep -nE '<[^>]+>' .env | grep -vE '^[0-9]+:[[:space:]]*#'
  exit 1
fi

COMPOSER="composer"
if command -v composer2 >/dev/null 2>&1; then COMPOSER="composer2"; fi

echo "==> Installing PHP dependencies"
# --no-scripts: Hostinger disables proc_open, which Composer's post-install
# script needs. That script only runs "artisan package:discover", done below.
"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-scripts
"$PHP" artisan package:discover --ansi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "==> Generating the application key (back up backend/.env afterwards!)"
  "$PHP" artisan key:generate --force
fi

echo "==> Creating the database tables"
"$PHP" artisan migrate --force

mkdir -p storage/app/private storage/fonts storage/framework/{cache,sessions,views} storage/logs
# Writable by the web server; capital X keeps files non-executable (git tracks the mode).
chmod -R u+rwX,g+rwX storage bootstrap/cache
git -C "$APP_DIR" config core.fileMode false

echo "==> Rebuilding caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo
echo "==> Done. Next: create your company and first administrator:"
echo "    $PHP artisan app:create-company \"Your Company Ltd\" --admin-name=\"Your Name\" --admin-email=you@example.com"
