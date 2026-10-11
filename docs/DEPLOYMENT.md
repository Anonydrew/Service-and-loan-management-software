# Deployment

**Hostinger:** follow the step-by-step guide in [DEPLOY-HOSTINGER.md](DEPLOY-HOSTINGER.md). This page summarises
the same setup for any Apache/LiteSpeed host or VPS.

## Layout

The web app and the API are served from **one address**:

```
https://loans.example.com/            → web app (frontend build copied into backend/public)
https://loans.example.com/api/*       → Laravel API
```

- The domain's document root is `backend/public` (or a symlink to it). Nothing else in the repository may be web-reachable.
- `deploy/build-frontend.ps1` builds the app into `deploy/out/web-app.zip`. Extract it into `backend/public`, next to `index.php`. Git ignores these files.
- Laravel serves `index.html` for every non-API path (`routes/web.php`), so deep links and refreshes work. The page is sent `no-cache`; hashed JS and CSS files are cached for a year (`public/.htaccess`).
- The SPA authenticates with Sanctum session cookies. It reads its CSRF token from `GET /api/csrf-token` and sends it as `X-CSRF-TOKEN`. It does not rely on the `XSRF-TOKEN` cookie, which other Laravel apps on the same parent domain would overwrite.

## Environment

Start from [`backend/.env.production.example`](../backend/.env.production.example). Key points:
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` = the public address.
- `SESSION_DOMAIN=null` (host-only cookie), a unique `SESSION_COOKIE` name, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- `SANCTUM_STATEFUL_DOMAINS` = the host (no scheme).
- `QUEUE_CONNECTION=sync`: nothing is queued.
- `BIOMETRICS_ALLOW_SIMULATED=false`. The simulator is refused in production anyway.

Generate `APP_KEY` once and **back it up**. Losing it makes encrypted identity numbers and biometric references unrecoverable.

## Install and update

| | Command |
|---|---|
| First install | `bash deploy/server-install.sh` (after filling in `.env`), then `php artisan app:create-company "Name" --admin-name=... --admin-email=...` |
| Update | `bash deploy/server-update.sh` (pull, composer, maintenance mode, migrate, rebuild caches) |

Both scripts call Composer with `--no-scripts` and run `package:discover` separately, because shared hosts often disable `proc_open`. Never run `db:seed`, `migrate:fresh` or `migrate:rollback` in production. The demo seeder refuses to run there, and its passwords are public.

## Scheduled jobs

| Command | When | |
|---|---|---|
| `php artisan loans:process-daily` | daily 00:30 | penalties, arrears, loan status |
| `php artisan biometrics:purge-expired` | daily 02:00 | biometric retention |

On a VPS, a single `* * * * * php artisan schedule:run` covers both. On shared hosting without `proc_open`, add them as two separate cron jobs.

## Hardening checklist

- [ ] HTTPS everywhere. HSTS is sent automatically on HTTPS requests.
- [ ] `APP_DEBUG=false`; Settings → Integrations never shows "Simulator".
- [ ] A database user limited to this schema; daily backups of the database and `storage/app/private`, with a tested restore.
- [ ] `APP_KEY` and any provider secrets stored outside the server and the repository.
- [ ] Staff on least-privilege roles and branch assignments. Second-person approvals (loan decisions, refunds, document verification, manual review) held by different people.
- [ ] Onboarding policy reviewed with your compliance officer. Registered as a data controller where required (Data Protection Act, 2012, Act 843). L.I. 2523 duties for Ghana Card holders understood (see BIOMETRICS.md).
- [ ] An SMS driver configured if customers should receive receipts. The default `log` driver sends nothing.
