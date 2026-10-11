# Service & Loan Manager

A multi-branch, multi-company management platform for a business that provides **loans** and **other customer services**: customer onboarding with identity verification, the full loan lifecycle, service requests, billing, double-entry accounting, reporting and audit.

| | |
|---|---|
| Backend | Laravel 13 · PHP 8.3 · Sanctum (SPA cookie auth) · dompdf — `backend/` |
| Frontend | React 19 · TypeScript · Tailwind CSS v4 · TanStack Query · React Router — `frontend/` |
| Database | SQLite for development and tests, MySQL 8 in production |
| Tests | PHPUnit: 68 tests / 448 assertions (`cd backend && php artisan test`) |

## Quick start (development)

```bash
# Backend (PHP 8.3 with pdo_sqlite, bcmath, gd, intl)
cd backend
php ../composer.phar install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # demo company, staff, customers, loans, invoices
php artisan serve                   # http://127.0.0.1:8000

# Frontend
cd ../frontend
npm install
npm run dev                         # http://localhost:5173 (proxies /api and /sanctum)
```

Sign in at <http://localhost:5173> with a demo account (password `Demo-Password-2026`, **development only**):

| Email | Role |
|---|---|
| admin@demo.test | Administrator (all permissions, all branches) |
| manager@demo.test | Branch Manager (Accra Central) |
| officer@demo.test | Loan Officer (Accra Central) |
| kumasi.officer@demo.test | Loan Officer (Kumasi Adum) — sees only Kumasi records |
| cashier@demo.test | Cashier |
| service@demo.test | Customer Service Officer |
| accountant@demo.test | Accountant |
| compliance@demo.test | Compliance Officer (document verification, manual identity review, audit) |

To try face verification without a provider, set `BIOMETRICS_FACE_DRIVER=simulated` and `BIOMETRICS_ALLOW_SIMULATED=true` in `backend/.env`. The simulator is refused in production and every result is labelled **SIMULATED**.

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): modules, data model, tenancy and branch isolation, accounting postings, security controls, API overview
- [docs/BIOMETRICS.md](docs/BIOMETRICS.md): onboarding-only biometric verification, provider and fingerprint-hardware integration requirements
- [docs/SETUP.md](docs/SETUP.md): environment variables, local setup, tests
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md): production deployment, migrations, scheduler, hardening checklist

## Modules

Dashboard · Customers & onboarding (consent, documents, biometrics, manual review) · Loans (products, applications, eligibility, maker-checker approval, agreements, disbursement, schedules, repayments, arrears, collections, write-off) · Services (catalogue, requests, appointments, assignments) · Billing (invoices, payments, receipts, two-person refunds, reconciliation) · Accounting (chart of accounts, journals, expenses, trial balance, income statement, balance sheet) · Branches, staff, roles & permissions · Reports with CSV export · In-app notifications and customer SMS log · Audit log · Settings.
