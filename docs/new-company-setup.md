# New company setup

The checklist for bringing a new company onto the system, from an empty server to the client's staff using it.
Each company gets **its own server, database and files**; companies never share anything. Every company runs the same release.

This guide is the order of work. The detail lives in two other documents, linked at each step:

- [`deploy/README.md`](../deploy/README.md) — the operations runbook (Forge, server, releases, backups, support access).
- [`docs/go-live-runbook.md`](go-live-runbook.md) — the cutover: importing the client's data and the first weeks live.

Tick each box as you go and keep this checklist with the company's file.

---

## 0. Before you start — collect from the client

- [ ] Company legal name in **English and Arabic**, CR number, address (EN/AR), phone, email, website.
- [ ] Logo (PNG/JPG, max 2 MB) and, if they want it, their contract letterhead image.
- [ ] VAT registration details, if registered.
- [ ] The hostname they want (for example `rent.company.bh`) and who controls their DNS.
- [ ] Their mail settings for system emails (SMTP host, port, username, password, the "from" address).
- [ ] The first **Admin**: name and email (a real person at the company).
- [ ] The staff list with each person's role: **Finance**, **Management**, **Leasing**, **Property Manager**, **Admin**. One person may hold several roles. For Leasing staff, which buildings they look after.
- [ ] The **cutover date** — always the 1st of a month (see the go-live runbook).

From your side (the vendor):

- [ ] The vendor-support email for this company's Vendor Support account.
- [ ] A short company code for error reports (`RMS_COMPANY_CODE`, e.g. `ACME`).
- [ ] An S3-compatible backup bucket for this company, at a different provider from the server.
- [ ] A place in the vault for this company's passwords (database users, backup archive password, Vendor Support login).

## 1. Server

Server size: 2 vCPU, 4 GB RAM, Ubuntu 26.04, PHP 8.5, MySQL 26.7. No Redis.

**With Laravel Forge (standard):** follow [`deploy/README.md` §2](../deploy/README.md#2-provision-a-server) exactly — Custom VPS, `provision-mysql.sh`, zero-downtime site on the `release` branch, shared `storage`, SSL, `.env`, queue worker, scheduler with heartbeats, monitors, uptime check on `/health`.

**Without Forge** (a plain VPS, e.g. Hostinger KVM): do the same steps by hand — see [Appendix A](#appendix-a-plain-vps-without-forge).

- [ ] Server created, firewall allows only SSH (22), HTTP (80) and HTTPS (443).
- [ ] MySQL installed and users created with `deploy/provision-mysql.sh` (both passwords in the vault).
- [ ] Site deployed from the `release` branch; `storage` survives deploys.
- [ ] SSL certificate issued and HTTPS works.
- [ ] `.env` filled in (see [Appendix B](#appendix-b-env-for-a-company)).
- [ ] Queue worker running (1 worker).
- [ ] Scheduler running every minute; one heartbeat per scheduled job.
- [ ] `https://<host>/health` answers `{"status":"up", ...}` and the uptime monitor points at it.

## 2. Install the company

On the server, in the site folder:

```bash
php artisan migrate --database=migrator --force
```

```bash
php artisan rms:install --company="Company Name W.L.L." --admin-name="Admin Name" --admin-email="admin@company.bh" --vendor-email="support+company@yourfirm.bh"
```

This runs once, on an empty database. It creates the roles, the company record, the number sequences, the default contract template, banks, facilities and unit types, the first Admin and the Vendor Support account. It then emails the Admin a link to set their password, so **mail must already work**.

- [ ] The command printed `Installed: <company>`.
- [ ] The **Vendor Support password and TOTP link** it printed are stored in the vault now — they are never shown again.
- [ ] The Admin received the password email (check spam). If not, fix `MAIL_*` and use "Forgot password" on the login page.
- [ ] `php artisan rms:integrity-check` prints `Integrity check passed.`

To start again from scratch (for example after UAT), see [`deploy/README.md` §5](../deploy/README.md#5-a-companys-go-live-spec-132): drop the database and `storage/app/private`, re-run `provision-mysql.sh`, deploy, install again.

## 3. Company setup — done by the client's Admin in the app

Sit with the Admin for this; it takes about an hour.

- [ ] **Sign in** and turn on **two-factor login** (required for Admin, Finance, Management and Vendor Support).
- [ ] **Administration → Company settings:** names (EN/AR), CR number, addresses, phone, email, website, logo, contract letterhead, stamp-paper space at the top of contracts, VAT (registered, TRN, rate, default tax for residential and commercial units), default grace days, invoice lead days, proration basis.
- [ ] **Administration → Users:** add every staff member and tick their roles. Several roles on one person are fine (for example the owner as Admin + Management + Finance). Assign buildings to Leasing users. Each user gets a password email.
- [ ] **Administration → Banks, Facilities, Unit types:** check the starting lists; add what the company uses, switch off what it doesn't.
- [ ] **Administration → Contract templates:** review the default English/Arabic agreement wording and adjust clauses if needed.
- [ ] Remind them: whoever **requests** a payment reversal, credit note, payment out or deposit settlement can never **approve** it; a second person always approves.

## 4. Load the company's existing data

Follow [`docs/go-live-runbook.md`](go-live-runbook.md). In short:

- [ ] Client downloads the templates (**Data import → Download template**) and fills them in: buildings, units, owners, owner contracts, tenants, agreements, then opening tenant balances, deposits held, cheques and owner balances. ID, phone, IBAN, cheque-number and money columns must be formatted as **Text** in Excel.
- [ ] **Dry run** the full set (nothing is saved) until it reports no problems.
- [ ] UAT on this server with the client (the runbook's UAT checklist).
- [ ] After sign-off: reinstall clean (§2), then on the evening before the cutover date run the real import and set the date:

```bash
php artisan rms:setting go_live_at 2026-11-01
```

- [ ] Reconcile the import totals against the old system with the client's Finance lead.

## 5. Before handover

- [ ] The go-live runbook's **security review** is signed (production mode, HTTPS, two-factor, database users, `composer audit`, backup restored on staging, heartbeats, roles reviewed).
- [ ] Last night's backup exists (`php artisan backup:list`) and the weekly restore check is scheduled for this company ([`deploy/README.md` §6](../deploy/README.md#6-backups-and-the-restore-check)).
- [ ] Training done, one session per role: Leasing, Finance, Management.
- [ ] Client has the user guide.
- [ ] The first nightly jobs ran: invoices issued at 01:00, integrity check at 02:30, backup at 03:30, digests at 07:00.

---

## Appendix A: plain VPS without Forge

The same setup Forge does, by hand, on Ubuntu 26.04. Run as root unless noted.

**Packages**

```bash
apt install nginx supervisor unzip git php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-zip php8.5-gd php8.5-curl php8.5-intl php8.5-bcmath
```

Install Composer and Node.js (for `npm run build`), then MySQL with `deploy/provision-mysql.sh`.

**Code** (as a deploy user, e.g. in `/var/www/rms`):

```bash
git clone --branch release <repo> /var/www/rms && cd /var/www/rms
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --database=migrator --force
php artisan optimize
```

`storage/` and `bootstrap/cache/` must be writable by the PHP-FPM user.

**PHP upload limits** — in `/etc/php/8.5/fpm/php.ini` (documents are up to 10 MB):

```ini
upload_max_filesize = 20M
post_max_size = 25M
```

**Nginx site** (`/etc/nginx/sites-available/rms`):

```nginx
server {
    listen 80;
    server_name rent.company.bh;
    root /var/www/rms/public;
    index index.php;
    client_max_body_size 25m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Then enable it, and add SSL with Let's Encrypt (`certbot --nginx -d rent.company.bh`). After SSL works, add HSTS.

**Queue worker** (`/etc/supervisor/conf.d/rms-worker.conf`):

```ini
[program:rms-worker]
command=php /var/www/rms/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/rms/storage/logs/worker.log
```

`supervisorctl reread && supervisorctl update`. After every deploy: `php artisan queue:restart`.

**Scheduler** (`crontab -u www-data -e`):

```cron
* * * * * cd /var/www/rms && php artisan schedule:run >> /dev/null 2>&1
```

**Deploying an update:** `git pull`, `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan migrate --database=migrator --force`, `php artisan optimize`, `php artisan queue:restart`. Forge's zero-downtime deploys avoid the short gap this causes; on a plain VPS, deploy outside office hours.

## Appendix B: `.env` for a company

Secrets come from the vault; never commit them.

| Key | Value |
|---|---|
| `APP_NAME` | `"Rental Management"` |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://<host>` — email links use this host |
| `APP_KEY` | from `php artisan key:generate` |
| `DB_HOST` `DB_PORT` `DB_DATABASE` | `127.0.0.1` `3306` `rms` |
| `DB_USERNAME` / `DB_PASSWORD` | `rms_app` / vault |
| `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` | `rms_migrate` / vault |
| `SESSION_DRIVER` `CACHE_STORE` `QUEUE_CONNECTION` | `database` |
| `SESSION_LIFETIME` / `SESSION_SECURE_COOKIE` | `30` / `true` |
| `MAIL_MAILER` `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_PASSWORD` `MAIL_SCHEME` | the company's mail provider |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | e.g. `no-reply@company.bh` / company name |
| `SENTRY_LARAVEL_DSN` / `SENTRY_TRACES_SAMPLE_RATE` | shared DSN / `0` |
| `RMS_COMPANY_CODE` | the short company code |
| `BACKUP_DISKS` | `backups-s3` |
| `BACKUP_ARCHIVE_PASSWORD` | vault (needed to restore — keep it safe) |
| `BACKUP_NOTIFY_EMAIL` | your ops mailbox |
| `BACKUP_S3_KEY` `_SECRET` `_REGION` `_BUCKET` `_ENDPOINT` | this company's bucket |
| `DB_DUMP_BINARY_PATH` | `/usr/bin` |
| `HEARTBEAT_*` (12 keys) | one ping URL per scheduled job, see `.env.example` |

The timezone is fixed to Asia/Bahrain in the code; there is no setting for it.

## Appendix C: useful commands

| Command | What it does |
|---|---|
| `php artisan rms:integrity-check` | Checks balances, credit, deposits and the protection triggers. Also runs nightly at 02:30 and emails Vendor Support if anything is wrong. |
| `php artisan rms:setting go_live_at YYYY-MM-DD` | Sets the cutover date. |
| `php artisan rms:setting require_different_approver true\|false` | Whether a second person must approve requests (keep `true`). |
| `php artisan rms:vendor-support --enable` / `--disable` | Turns the Vendor Support login on or off. Only the vendor can re-enable it. |
| `php artisan rms:number-sequences --year=YYYY` | Creates next year's invoice/receipt numbering (also runs every 1 December). |
| `php artisan backup:list` / `backup:run` | Lists backups / takes one now. |
| `GET /health` | `{"status":"up"}` when the app and database are working. |
