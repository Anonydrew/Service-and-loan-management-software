# Architecture

## 1. Shape of the system

```
React SPA (frontend/)  ──HTTPS, session cookie + XSRF──▶  Laravel API (backend/, /api/*)
                                                            │
                                   ┌────────────────────────┼─────────────────────────┐
                                   ▼                        ▼                         ▼
                           MySQL / SQLite        Private disk (storage/app/private)   Integrations
                           (all business data)   identity documents only             biometric provider, SMS
```

- **Backend:** a stateless JSON API. Business rules live in `app/Services/*`; controllers validate input, call a service and return JSON. Money is integer minor units (pesewas) everywhere; `App\Support\Money` is the only place decimals are converted (with bcmath).
- **Frontend:** a single-page app. In development Vite proxies `/api` and `/sanctum`, so the SPA and API share an origin. In production, serve the SPA from the same site (see DEPLOYMENT.md).

### Backend layout

| Path | Purpose |
|---|---|
| `app/Support/Tenancy/*` | Company context (`Tenant`), `CompanyScope` (fails closed), `BranchScope`, `BelongsToCompany`, `BelongsToBranch` |
| `app/Support/Permissions.php` | Single source of truth for permission codes and default roles |
| `app/Support/Audit/*` | Append-only audit logger and the `Auditable` model trait |
| `app/Services/Customers/*` | Registration, consent, documents, onboarding policy and manual review |
| `app/Services/Biometrics/*` | Provider interface, drivers, attempt lifecycle (onboarding only) |
| `app/Services/Loans/*` | Calculator, eligibility, lifecycle, repayment allocation, arrears and penalties |
| `app/Services/ServiceDesk/*` | Service request workflow |
| `app/Services/Billing/*` | Invoices, payments, refunds, reconciliation |
| `app/Services/Accounting/*` | Ledger (double entry), chart of accounts, expenses, statements |
| `app/Services/Reporting/Reports.php` | Report catalogue |
| `app/Http/Middleware/*` | `BindTenant`, `RequirePermission`, `SecurityHeaders` |
| `routes/api.php` | All endpoints, each with an explicit permission |
| `routes/console.php` | Scheduled jobs (daily loan processing, biometric retention purge) |

## 2. Multi-company and branch isolation

- Every business table has `company_id`. Models using `BelongsToCompany` get a global scope that adds `where company_id = <bound company>` to **every** select, update and delete. If no company is bound, the query **throws** (`MissingTenantException`) instead of running unfiltered.
- `company_id` is stamped from the bound context on insert. Request input cannot set it, and an update can never move a record between companies.
- `BindTenant` binds the signed-in user's company on every request. It runs **before** route-model binding (see `bootstrap/app.php`), so `/customers/{id}` for another company's record resolves to 404.
- **Branches:** customers, loans, applications, repayments, service requests, invoices, payments, refunds and expenses carry `branch_id`. `BranchScope` narrows them to the user's assigned branches unless the user holds `branches.all`. Writing into an inaccessible branch is refused. Child records such as documents and installments are reached through their branch-scoped parent (scoped route bindings).
- Duplicate identity numbers are checked company-wide (across branches) using a keyed hash, so the same person can't be registered twice.

## 3. Permissions

Permission codes are defined in `App\Support\Permissions::MODULES` (about 50 codes across 10 modules). A role is a named set of codes, and a user's access is the union of their roles. Default roles are Administrator, Branch Manager, Loan Officer, Cashier, Customer Service Officer, Accountant, Compliance Officer and Auditor (read-only).

- Every protected route declares `permission:<code>` (or `a|b` for any-of). The check runs before model binding, so a forbidden user gets 403 whether or not the record exists.
- Hiding menu items in the SPA is a convenience only.
- **Privilege escalation is blocked:** a user can't create or edit a role, or assign one to staff, if it grants permissions they don't hold themselves. The Administrator role can't be edited.
- **Separation of duties (configurable):**
  - Loan applications: whoever prepared an application can't approve or reject it (maker-checker).
  - Refunds: requester and approver must differ.
  - Documents: the uploader can't verify their own upload.
  - Manual identity review: the registrar can't decide.

## 4. Data model

```
companies ─┬─ branches ─── branch_user ─── users ─── role_user ─── roles(permissions json)
           │
           ├─ customers (individual | business; encrypted id_number + keyed hash)
           │    ├─ customer_representatives (natural persons for a business)
           │    ├─ customer_documents (private file path, checksum, verification)
           │    ├─ consent_records (type, policy version, text hash, method, captured_by)
           │    ├─ biometric_verifications (outcome + encrypted provider refs; no biometrics)
           │    └─ customer_messages (SMS history)
           │
           ├─ loan_products ── loan_applications ── loans ─┬─ loan_installments
           │                                               ├─ loan_repayments ── repayment_allocations
           │                                               └─ collection_activities
           │
           ├─ services ── service_requests ── service_request_updates
           │                     └── invoices ─┬─ invoice_items
           │                                   └─ payments ── refunds
           │
           ├─ accounts ── journal_entries ── journal_lines;  expenses
           └─ audit_logs (append-only), sequences (document numbers), notifications
```

Key rules:
- **Loan terms are snapshotted** onto the loan at approval (rate, method, fee, penalty, grace). Editing a product never changes existing loans.
- **The repayment schedule is generated at disbursement**, from the disbursement date. The calculator (`LoanCalculator`) supports flat and reducing-balance interest, monthly or annual rates, weekly, biweekly and monthly frequency, and deducted or capitalised fees. It uses bcmath and rounds half-up once per figure; the final installment absorbs rounding.
- **Repayment allocation:** oldest installment first; within an installment, penalty → fees → interest → principal. Payments above the outstanding balance are refused. Each repayment stores its per-installment split, so reversal undoes exactly what it did.
- **Penalties:** the daily job charges each installment once, after due date + grace, as a percentage of its unpaid amount or a fixed sum. Penalties are recognised as income when paid.
- **Document numbers** (CUS-, APP-, LN-, RCP-, SR-, INV-, PAY-, JE-, EXP-) come from a locked per-company counter, so they are gap-free and never duplicated.
- All financial operations run in database transactions with row locks (`lockForUpdate`) on the loan or invoice being changed.

## 5. Accounting postings

Every financial event posts one balanced journal entry in the same transaction. Unbalanced entries are refused.

| Event | Debit | Credit |
|---|---|---|
| Loan disbursed (fee deducted) | Loans Receivable (principal) | Cash/Bank/MoMo (net), Loan Fee Income (fee) |
| Loan disbursed (fee capitalised) | Loans Receivable (principal) | Cash/Bank/MoMo (principal) |
| Loan repayment | Cash/Bank/MoMo | Loans Receivable (principal), Interest Income, Fee Income, Penalty Income |
| Repayment reversed | mirror entry of the original | |
| Loan written off | Loan Write-offs (expense) | Loans Receivable (outstanding principal) |
| Invoice issued | Accounts Receivable (total) | Service Income (net of discount), Tax Payable |
| Invoice voided (unpaid only) | mirror entry of the original | |
| Invoice payment | Cash/Bank/MoMo | Accounts Receivable |
| Refund approved | Service Refunds (contra income) | Cash/Bank/MoMo |
| Expense | Expense account | Cash/Bank/MoMo |
| Manual journal | any balanced lines (accounting.manage) | |

Payment-method mapping: cash → Cash on Hand; mobile_money → Mobile Money Wallet; bank_transfer, cheque and card → Bank. Interest is recognised on a cash basis (when paid).

## 6. Security controls

| Control | Where |
|---|---|
| Session auth with CSRF (Sanctum SPA), bearer tokens for non-browser clients | `AuthController`, `bootstrap/app.php` (`statefulApi`) |
| Login throttling (IP and email+IP) and account lockout after 5 failures for 15 min | `AppServiceProvider`, `AuthController` |
| Disabled users or companies are signed out on their next request; password reset revokes tokens and sessions | `BindTenant`, `UserController` |
| Mass-assignment protection: explicit `$fillable`; workflow fields set only by services | models |
| IDOR protection: company and branch scopes on bound models; scoped child bindings | tenancy layer, routes |
| Identity numbers and document numbers encrypted at rest (`encrypted` cast), keyed hash for lookup, masked in output | `Customer`, `CustomerDocument`, `IdentityHash` |
| Identity documents on a private disk, MIME and extension checks, 10 MB limit, SHA-256 checksum, audited downloads with `no-store` | `DocumentService` |
| Biometric data minimisation: no images or templates stored; provider references encrypted and purged after retention | see BIOMETRICS.md |
| Append-only audit log (model-level guard), with secrets and identity values redacted | `AuditLogger`, `AuditLog` |
| Security headers (nosniff, frame deny, referrer policy, permissions policy, HSTS on HTTPS) | `SecurityHeaders` |
| Rate limits: API 240/min per user; biometrics 20/min per user plus a daily per-person attempt cap | `AppServiceProvider`, `BiometricService` |

## 7. API conventions

- JSON in and out. Lists are Laravel paginators (`data`, `current_page`, `last_page`, `total`, …) with `per_page` capped at 100.
- Money is sent as decimal strings in **major** units (`"1500.00"`) and returned as `*_minor` integers.
- Errors are `{ message, error_code, ...context }`. Validation errors are 422 with `errors`; business rules are 422 with a stable `error_code` (e.g. `maker_checker`, `overpayment`, `not_eligible`, `provider_not_configured`); permission failures are 403 `forbidden`; inaccessible records are 404 `not_found`.

Main endpoint groups (all under `/api`): `auth/*`, `dashboard`, `lookups`, `search`, `customers/*` (incl. `documents`, `consents`, `onboarding`, `biometrics`, `representatives`), `loan-products`, `loan-applications`, `loans`, `repayments`, `collections/arrears`, `services`, `service-requests`, `invoices`, `payments`, `refunds`, `reconciliation`, `accounts`, `journal-entries`, `expenses`, `statements/*`, `reports/{slug}`, `branches`, `users`, `roles`, `permissions`, `audit-logs`, `settings`, `notifications`, and `webhooks/biometrics/{provider}` (provider-signed).

## 8. Scheduled jobs

| Command | Schedule | Purpose |
|---|---|---|
| `loans:process-daily` | 00:30 | Charge late penalties, update days-in-arrears and loan status |
| `biometrics:purge-expired` | 02:00 | Clear provider references past retention; ask provider to delete |
| `app:create-company "Name"` | manual | Provision a real company (head office, roles, chart of accounts, admin) |
