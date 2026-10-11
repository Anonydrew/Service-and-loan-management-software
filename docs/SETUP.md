# Setup

## Requirements

- PHP 8.3+ with `pdo_sqlite` (dev), `pdo_mysql` (prod), `bcmath`, `mbstring`, `openssl`, `fileinfo`, `gd`, `intl`, `zip`
- Composer 2 (a `composer.phar` is included at the repository root)
- Node.js 20+ and npm
- MySQL 8 (production)

## Local development

```bash
cd backend
php ../composer.phar install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # or: php artisan migrate && php artisan app:create-company "Your Company"
php artisan serve                 # http://127.0.0.1:8000

cd ../frontend
npm install
npm run dev                       # http://localhost:5173
```

The Vite dev server proxies `/api` and `/sanctum` to `http://127.0.0.1:8000` (override with `VITE_DEV_API`). Run the scheduler locally with `php artisan schedule:work` if you want penalties and arrears to update.

## Environment variables (backend `.env`)

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Standard Laravel. `APP_KEY` also keys the identity-number hashes: **back it up; changing it makes encrypted fields unreadable.** |
| `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database |
| `SESSION_DRIVER=database`, `SESSION_LIFETIME`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true` (prod), `SESSION_DOMAIN` | Session cookie used by the SPA |
| `SANCTUM_STATEFUL_DOMAINS` | Host(s) the SPA is served from, e.g. `app.example.com` |
| `CORS_ALLOWED_ORIGINS` | SPA origin(s) if served from a different origin than the API |
| `FRONTEND_URL` | SPA URL |
| `SMS_DRIVER`, `SMS_SENDER_ID` | Customer SMS. `log` writes to `storage/logs` only; add a driver in `AppServiceProvider` for your SMS provider |
| `BIOMETRICS_*`, `FINGERPRINT_*` | See [BIOMETRICS.md](BIOMETRICS.md) |
| `MAIL_*` | Not used by core flows yet (password resets are admin-driven) |

Frontend (`frontend/.env`, optional): `VITE_API_BASE_URL` if the API is on a different origin than the SPA (default: same origin).

## Tests

```bash
cd backend && php artisan test           # 68 tests, SQLite in memory
cd frontend && npm run build && npx eslint src
```

The suites cover:
- **Tenancy:** company and branch isolation, IDOR, payload `company_id` ignored, fail-closed scope.
- **Permissions:** server-side enforcement, read-only auditor, escalation blocked, disabled users.
- **Authentication:** login, lockout, throttling.
- **Customer onboarding:** individual and business, encrypted IDs, duplicates, minors, four-eyes documents, upload restrictions, manual review.
- **Biometrics:** see BIOMETRICS.md.
- **Loans:** calculator, allocation, eligibility, maker-checker, full lifecycle with ledger assertions, reversal, penalties, write-off, product snapshots.
- **Services and billing:** taxed invoicing, partial payments, overpayment, two-person refunds, void, reconciliation, expenses.
- **Audit and reporting:** redaction, append-only, per-company visibility; reports permissions and CSV export.
