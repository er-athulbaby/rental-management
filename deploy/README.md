# Operations runbook

Everything here follows spec §13. One server per company; every company runs the same release.

## 1. Accounts the product owner provides (STOP AND ASK if missing)
- Laravel Forge on the **Business** plan (server monitoring and team roles; Hobby allows only one custom VPS).
- A VPS per company at a **Bahrain-based provider** (not AWS me-south-1): 2 vCPU, 4 GB RAM, fresh Ubuntu 26.04 x64, root SSH. Location confirmed under C3.
- S3-compatible object storage at a **different provider**, one bucket per company (C3 decides the location).
- A Sentry project (one DSN for all installs) and an uptime-monitoring service.
- DNS for each company's hostname.

## 2. Provision a server
1. In Forge: Servers → Create → **Custom VPS**, Ubuntu 26.04, **no database** (Forge has no MySQL 26.x), PHP 8.5. Add Forge's key to `/root/.ssh/authorized_keys`.
2. As root on the server: copy `deploy/provision-mysql.sh`, generate two passwords into the vault, then
   `RMS_APP_PASSWORD=... RMS_MIGRATE_PASSWORD=... bash provision-mysql.sh`.
3. In Forge: create the site with **zero-downtime deployments** (only selectable at creation), repository = this repo, branch = **`release`**.
4. Site → Settings → Deployments → **Shared paths**: add `storage` (uploads must survive release pruning; `.env` is shared automatically).
5. Paste `deploy/forge-deploy.sh` as the deploy script. Enable SSL (Let's Encrypt).
6. Environment (`.env`), no secrets in git:
   ```
   APP_NAME="Rental Management"   APP_ENV=production   APP_DEBUG=false   APP_URL=https://<host>
   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rms
   DB_USERNAME=rms_app DB_PASSWORD=<vault>   DB_MIGRATOR_USERNAME=rms_migrate DB_MIGRATOR_PASSWORD=<vault>
   SESSION_DRIVER=database SESSION_LIFETIME=30 SESSION_SECURE_COOKIE=true CACHE_STORE=database QUEUE_CONNECTION=database
   MAIL_* (company mail provider)
   SENTRY_LARAVEL_DSN=<dsn> SENTRY_TRACES_SAMPLE_RATE=0 RMS_COMPANY_CODE=<short code>
   BACKUP_DISKS=backups-s3 BACKUP_ARCHIVE_PASSWORD=<vault> BACKUP_NOTIFY_EMAIL=<ops list> DB_DUMP_BINARY_PATH=/usr/bin
   BACKUP_S3_KEY/SECRET/REGION/BUCKET/ENDPOINT=<bucket>
   HEARTBEAT_BACKUP_CLEAN/RUN/MONITOR, HEARTBEAT_NUMBER_SEQUENCES=<Forge heartbeat URLs>
   ```
7. Forge → Site → **Queue**: `database` connection, 1 worker. Server → **Scheduler**: `php artisan schedule:run` every minute. Enable "Monitor with heartbeats" for each scheduled job and paste each ping URL into the matching `HEARTBEAT_*` variable.
8. Server → Observe → **Monitors**: CPU, disk (alert at 80 %), memory; notify the ops distribution list.
9. Point the uptime service at `https://<host>/health` (Forge's deployment health checks run only after deploys).

## 3. Releases
```bash
git tag v1.2.0 && git push origin v1.2.0
git push origin v1.2.0^{commit}:refs/heads/release --force   # Forge deploys the release branch head
```
Deploy to **staging first**, then to each company. Before any release with migrations, run them against a copy of the largest company's database on the restore-check server. The version each company runs is shown in its footer and on `/health`.

## 4. Staging
Staging is a demo install with **fake data only** — never real company data. After provisioning (section 2):
```bash
php artisan migrate --database=migrator --force
php artisan rms:install --company="Demo Properties W.L.L." --admin-name="Demo Admin" --admin-email=<demo admin email> --vendor-email=<vendor support email>
```
Store the printed Vendor Support password and TOTP URL in the vault immediately.

## 5. A company's go-live (spec §13.2)
1. Provision its server (section 2) and deploy the current release.
   - In M1 the client gets the four templates (Data import → Download template, as Vendor Support): buildings, units, owners, owner contracts. Ask them to format ID, phone, IBAN and money columns as Text before typing. Owner contract `units` is `ALL` or comma-separated unit codes; dates are `YYYY-MM-DD` or `DD/MM/YYYY`.
2. `rms:install` with the company's details. Log in as Vendor Support, upload the client's files on Data import and press **Dry run** until it reports no problems (this is the M1 exit check: nothing is saved). Then UAT on this server.
3. After UAT sign-off: drop the database and `storage/app/private`, recreate the database with `provision-mysql.sh`, deploy, `rms:install` again, run the final import, then `php artisan rms:setting go_live_at <YYYY-MM-DD>`.

## 6. Backups and the restore check
- Nightly `backup:run` (03:30) sends an AES-256 archive to `backups-s3`; `backup:clean` keeps 30 dailies + 12 monthlies; `backup:monitor` mails if the newest backup is older than a day.
- **Weekly restore check** on a dedicated restore-check server (same location, no live credentials, wiped after each run): check out the release, set `BACKUP_DISKS=backups-s3`, that company's bucket and `BACKUP_ARCHIVE_PASSWORD`, `RESTORE_DB_*` pointing at a scratch database, then
  `php artisan backup:restore --disk=backups-s3 --connection=restore --reset --keep --no-interaction` → expect "All health checks passed."
- Quarterly: a manual full restore drill onto a fresh server.

## 7. Support access
- Vendor Support logs in with the vault password and TOTP. Admin can deactivate it; only the vendor re-enables it: `php artisan rms:vendor-support --enable`.
- Install-level switches: `php artisan rms:setting require_different_approver false|true`, `php artisan rms:setting go_live_at YYYY-MM-DD`.
