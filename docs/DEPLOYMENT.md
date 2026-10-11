# Deployment

The recommended layout serves the SPA and the API from **one site**, so Sanctum session cookies are first-party and no CORS is needed:

```
https://app.example.com/            → frontend/dist (static SPA, index.html fallback)
https://app.example.com/api/*       → Laravel backend/public/index.php
https://app.example.com/sanctum/*   → Laravel backend/public/index.php
```

## 1. Build

```bash
cd frontend && npm ci && npm run build            # → frontend/dist
cd ../backend && php ../composer.phar install --no-dev --optimize-autoloader
```

Copy the contents of `frontend/dist/` into `backend/public/`, next to `index.php`. With Apache, add an SPA fallback to `backend/public/.htaccess` **before** Laravel's front-controller rule:

```apache
# Serve the SPA for any non-API path that isn't a real file
RewriteCond %{REQUEST_URI} !^/(api|sanctum|up)(/|$)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.html [L]
```

On shared hosting (e.g. Hostinger), point the domain's document root at `backend/public`, or symlink `public_html` to it.

## 2. Environment

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
DB_CONNECTION=mysql
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
BIOMETRICS_ALLOW_SIMULATED=false
```

Generate `APP_KEY` once (`php artisan key:generate`) and **store it in your secrets vault**. Losing it makes encrypted identity numbers and biometric references unrecoverable.

## 3. Database and first company

```bash
php artisan migrate --force
php artisan app:create-company "Your Company Ltd" --admin-name="Jane Doe" --admin-email=jane@yourco.com
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Never run `db:seed` in production. The demo seeder refuses to run there, and its passwords are public.

**Upgrades:** take a backup, run `php artisan down`, deploy the code, `php artisan migrate --force`, rebuild the caches, then `php artisan up`. Migrations are additive. Don't run `migrate:fresh` or `migrate:rollback` against production.

## 4. Scheduler

Add one cron entry:

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

This runs `loans:process-daily` (00:30) and `biometrics:purge-expired` (02:00). No queue worker is required: notifications and audit entries are written synchronously.

## 5. Files and permissions

- `storage/` and `bootstrap/cache/` must be writable by the web server.
- Identity documents are stored in `storage/app/private/customer-documents/{company}/{customer}/`. Include this directory in backups and keep it **outside** the web root (the default).

## 6. Hardening checklist

- [ ] HTTPS everywhere; HSTS is sent automatically on HTTPS requests.
- [ ] `APP_DEBUG=false`; `BIOMETRICS_ALLOW_SIMULATED=false`.
- [ ] Database user limited to this schema; daily encrypted backups of the database and `storage/app/private`, with a tested restore.
- [ ] `APP_KEY` and provider secrets stored outside the repository.
- [ ] Change the first administrator's password on first sign-in; give staff least-privilege roles and branch assignments.
- [ ] Review the onboarding policy in Settings with your compliance officer, and register as a data controller where required (Ghana Data Protection Act, 2012, Act 843).
- [ ] Configure an SMS driver if customers should receive receipts; the default `log` driver sends nothing.
- [ ] Monitor `storage/logs/laravel.log` for reported exceptions, including failed audit writes.
