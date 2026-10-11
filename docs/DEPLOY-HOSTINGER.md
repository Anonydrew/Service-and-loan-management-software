# Deploying to Hostinger (Business / Cloud web hosting)

A step-by-step guide to putting the DXCOVR Service & Loan Manager online on a Hostinger **Business** or
**Cloud** plan (managed with hPanel). Follow the steps in order the first time. After that, updating is one
command plus, if the web app changed, one upload (step 13).

Plan on about an hour for the first deployment, most of it waiting for the SSL certificate.

---

## What you will end up with

One subdomain serves both the web app and its API. In this guide `yourdomain.com` stands for your real domain
and `loans` for the subdomain; replace them everywhere.

```
 Browser ──> https://loans.yourdomain.com/            web app (index.html + assets, built on your PC)
         └─> https://loans.yourdomain.com/api/...     Laravel API ──> MySQL
                                                       │
 Hostinger cron (twice a night) ───────────────────────┘   penalties/arrears, biometric data retention
```

Why one address: the sign-in cookie never has to cross subdomains, so there is no CORS or cookie-domain
setup to get wrong, and this app can't clash with the clinic ERP's cookies on the same domain.

## Before you start

- [ ] A Hostinger **Business** or **Cloud** plan with `yourdomain.com` added.
- [ ] The latest code pushed to GitHub (`main` branch).
- [ ] On your PC: Node.js and Windows PowerShell (both already used to run the app locally).
- [ ] A notepad file for the values you'll create: database name, database user, database password,
      SSH username, SSH port, server IP, and later the **APP_KEY** (step 7).

---

## Step 1. Create the subdomain

1. Log in to **hPanel** and open the website for `yourdomain.com`.
2. Go to **Domains → Subdomains** and create `loans`.
3. hPanel shows the subdomain's **folder**, for example `/domains/yourdomain.com/public_html/loans`.
   **Write it down.** You need it in step 8.

## Step 2. Turn on HTTPS

1. Go to **Security → SSL** and install the free certificate for `loans.yourdomain.com`.
   It can take 10 to 30 minutes; carry on meanwhile.
2. When it's active, switch on **Force HTTPS** if hPanel offers it.

HTTPS is required: the sign-in cookie is only sent over secure connections.

## Step 3. Set PHP to 8.3 with the right extensions

1. Go to **Advanced → PHP Configuration**, choose **PHP 8.3**, save.
2. On the **PHP extensions** tab make sure these are ticked:
   `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`,
   `tokenizer`, `xml`, `zip`. `bcmath` matters most: every money calculation uses it.
3. On the **PHP options** tab, check `upload_max_filesize` and `post_max_size` are at least **12M**
   (identity documents can be up to 10 MB).

## Step 4. Create the MySQL database

1. Go to **Databases → Management**.
2. Create a database, e.g. name `loans`, user `loans`, and a strong password.
3. Hostinger adds your account prefix, so the real names look like `u123456789_loans`.
   **Write down the full database name, full username and the password.**

The full test suite passes on MariaDB 10.11 (MySQL-compatible) as well as SQLite.

## Step 5. Connect to the server over SSH

1. Go to **Advanced → SSH Access** and enable it. Note the **IP**, **port** (usually `65002`) and
   **username** (`u123456789`).
2. On your PC, open **Windows PowerShell** and connect:

   ```powershell
   ssh -p 65002 u123456789@123.45.67.89
   ```

3. Check the command-line PHP version:

   ```bash
   php -v
   ```

   It must say **8.3**. If not, use `/opt/alt/php83/usr/bin/php` (check with `ls /opt/alt/php83/usr/bin/php`).
   Wherever this guide says `php`, use that path, and run the scripts as `PHP_BIN=/opt/alt/php83/usr/bin/php bash ...`.

## Step 6. Get the code from GitHub

If the repository is **public**, just clone it:

```bash
git clone https://github.com/Anonydrew/Service-and-loan-management-software.git ~/loans
```

If it is **private**, give the server a read-only key first (you may already have one from the clinic ERP:
GitHub requires a separate deploy key per repository, so make a new one):

```bash
ssh-keygen -t ed25519 -C "hostinger-loans" -f ~/.ssh/github_loans -N ""
cat ~/.ssh/github_loans.pub
```

On GitHub: repository → **Settings → Deploy keys → Add deploy key**, title `Hostinger`, paste the line, leave
**Allow write access** unticked. Then:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-loans
  HostName github.com
  IdentityFile ~/.ssh/github_loans
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
git clone git@github-loans:Anonydrew/Service-and-loan-management-software.git ~/loans
```

The code is now in `~/loans`.

## Step 7. Configure and install the backend

Create the settings file from the production template and fill it in:

```bash
cd ~/loans/backend
cp .env.production.example .env
nano .env
```

Replace every `<...>` placeholder:

| Setting | Put |
|---|---|
| every `<yourdomain.com>` | your domain, e.g. `example.com` (so `loans.example.com`) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the values from step 4 |

If you chose a subdomain other than `loans`, change `loans.` in `APP_URL`, `FRONTEND_URL`,
`SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` to match. Save with **Ctrl+O**, **Enter**, exit with **Ctrl+X**.

Then run the installer:

```bash
bash ~/loans/deploy/server-install.sh
```

It checks PHP and its extensions, refuses to run while `<placeholders>` remain, installs dependencies,
generates the application key, creates the database tables and builds Laravel's caches.

**Back up the application key now.** Run `grep APP_KEY ~/loans/backend/.env` and save the line in your password
manager. Identity numbers and biometric references are encrypted with it: if the server is lost and the key with
it, that data cannot be recovered.

## Step 8. Point the subdomain at Laravel

Only Laravel's `public` folder may be reachable from the web, never the whole backend. Replace the subdomain's
folder with a link to it, using the **folder from step 1**:

```bash
SITE_DIR=~/domains/yourdomain.com/public_html/loans     # <- your folder from step 1

mv "$SITE_DIR" "$SITE_DIR.original"
ln -s ~/loans/backend/public "$SITE_DIR"
ls -la "$SITE_DIR"     # should show:  loans -> /home/u123456789/loans/backend/public
```

Check in your browser: **`https://loans.yourdomain.com/up`** should show a page saying the application is up.
`https://loans.yourdomain.com/` will say the web app hasn't been built yet: that's step 9.

## Step 9. Build and upload the web app

The web app is built on **your PC**, not on the server.

1. In **Windows PowerShell** on your PC:

   ```powershell
   cd "C:\Users\User\Desktop\Service and loan management software"
   powershell -ExecutionPolicy Bypass -File .\deploy\build-frontend.ps1
   ```

   It type-checks, lints and builds the app and creates `deploy\out\web-app.zip`.

2. Copy it to the server and unpack it into Laravel's `public` folder (still in PowerShell, with your values):

   ```powershell
   scp -P 65002 .\deploy\out\web-app.zip u123456789@123.45.67.89:~/loans/
   ssh -p 65002 u123456789@123.45.67.89 "rm -rf ~/loans/backend/public/assets && unzip -o ~/loans/web-app.zip -d ~/loans/backend/public && rm ~/loans/web-app.zip"
   ```

   Prefer the browser? In hPanel **Files → File Manager**, open `loans/backend/public` in your home folder,
   delete the old `assets` folder if there is one, upload `web-app.zip`, right-click → **Extract** here, then
   delete the zip. `index.html` and `assets` must sit **next to `index.php`**, not in a subfolder.

3. Open **`https://loans.yourdomain.com`**: you should see the sign-in page.

## Step 10. Nightly jobs (cron)

Two commands must run once a day. Hostinger's shared hosting disables the function Laravel's scheduler needs,
so each gets its own cron job.

1. Find the full paths:

   ```bash
   which php                       # e.g. /usr/bin/php  (or the /opt/alt/php83/... path from step 5)
   echo ~/loans/backend/artisan    # e.g. /home/u123456789/loans/backend/artisan
   ```

2. In hPanel go to **Advanced → Cron Jobs → Custom** and add (with your two paths):

   | Schedule | Command | What it does |
   |---|---|---|
   | `30 0 * * *` (00:30 daily) | `/usr/bin/php /home/u123456789/loans/backend/artisan loans:process-daily` | Late-payment penalties, days in arrears, loan status |
   | `0 2 * * *` (02:00 daily) | `/usr/bin/php /home/u123456789/loans/backend/artisan biometrics:purge-expired` | Clears biometric provider references past their retention date |

Both are safe to run more than once: a loan is never penalised twice for the same installment.

## Step 11. Create your company and first administrator

Over SSH:

```bash
cd ~/loans/backend
read -s -p "Administrator password: " ADMIN_PASSWORD; echo; export ADMIN_PASSWORD
php artisan app:create-company "DXCOVR Trust Services" --admin-name="Your Name" --admin-email=you@yourdomain.com
unset ADMIN_PASSWORD
```

The password needs at least 10 characters with upper and lower case letters and a number. `read -s` keeps it
out of the screen and the shell history. This creates the head-office branch, the default roles, the chart of
accounts and your administrator login. It adds **no demo data**: never run `db:seed` on the server.

## Step 12. First sign-in and checks

1. Open `https://loans.yourdomain.com` and sign in with the administrator you just created.
2. **Settings:** fill in the company profile (it appears on agreements, invoices and receipts) and review the
   onboarding policy.
3. **Branches:** rename the head office and add your other branches.
4. **Staff:** create staff accounts with the right roles and branches. Give the second-person approvals
   (loan decisions, refunds, document verification, manual review) to different people.
5. **Loan products** and **Service catalogue:** set up what you offer.

Checklist:

- [ ] `https://loans.yourdomain.com/up` shows "up"
- [ ] Sign-in works, and refreshing a page like `/customers` still works
- [ ] A loan agreement or invoice PDF downloads
- [ ] **Settings → Integrations** shows biometrics as *Not configured* (correct until a real integration is
      installed; see below) and never *Simulator*
- [ ] The application key is saved somewhere safe (step 7)

### Ghana Card holders and the fingerprint scanner

Until the DERMALOG ZF1+ capture bridge is installed and NIA has approved your setup, **Ghana Card holders
cannot complete onboarding**. That is deliberate: L.I. 2523 requires a biometric check and forbids relying on the
card's image or appearance. Customers using other IDs (e.g. passports) can be onboarded with document checks.
See [BIOMETRICS.md](BIOMETRICS.md) for what to obtain from NIA and DERMALOG.

## Step 13. Updating to a new version

1. **On your PC:** commit and push to GitHub as usual.
2. **Backend:** over SSH:

   ```bash
   bash ~/loans/deploy/server-update.sh
   ```

   It pulls from GitHub, installs dependencies, runs new migrations and rebuilds the caches, with a short
   maintenance page meanwhile. (With a non-default PHP: `PHP_BIN=/opt/alt/php83/usr/bin/php bash ...`.)
   Your uploaded web app files are not touched by `git pull`.

3. **Web app:** if anything in `frontend` changed, repeat step 9.

---

## Troubleshooting

| What you see | Likely cause and fix |
|---|---|
| "The web app has not been built into backend/public yet" | Step 9 wasn't done, or the zip was extracted into a subfolder. `index.html` must be in `~/loans/backend/public`. |
| **419** or "page expired" when signing in or saving | The site isn't on HTTPS (step 2), or `SESSION_SECURE_COOKIE=true` while testing over plain http. After any `.env` change run `php artisan config:cache`. |
| Sign-in seems to work but you land back on the sign-in page | `SANCTUM_STATEFUL_DOMAINS` doesn't match the address in the browser exactly (no `https://`, no trailing slash). Fix it, then `php artisan config:cache`. |
| **500** error | Read the end of today's log: `tail -n 50 ~/loans/backend/storage/logs/laravel-$(date +%F).log` |
| Old version still showing after an update | Hard-refresh the browser (Ctrl+F5). The page itself is never cached, so this only happens if step 9 was skipped. |
| `https://loans.yourdomain.com` shows a **file list** or **403** | Step 8's link points to the wrong place. `ls -la` the subdomain folder and check it points to `.../loans/backend/public`. |
| `Permission denied` writing logs, cache or PDFs | `chmod -R u+rwX,g+rwX ~/loans/backend/storage ~/loans/backend/bootstrap/cache` |
| Uploading a document fails for larger files | Raise `upload_max_filesize` and `post_max_size` (step 3). |
| `composer`: "requires php ^8.3" | The command line uses older PHP; see step 5. |
| Penalties never appear on overdue loans | Check the cron jobs (step 10) and their PHP path. Run the command by hand over SSH to see any error. |

## Backups

- hPanel takes automatic backups (**Files → Backups**). Before a big update also export the database from
  **Databases → phpMyAdmin → Export**.
- Keep a copy of **`backend/.env`** (above all `APP_KEY`) somewhere safe and separate from the server.
- Uploaded identity documents live in `~/loans/backend/storage/app/private`; make sure your backups include it.
