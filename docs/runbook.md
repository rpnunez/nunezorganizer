# NunezOrganizer production runbook

Running NunezOrganizer at **https://organizer.nunezserver.online** on the Webuzo/OpenLiteSpeed VPS that also hosts DevStackTips (issue #21).

> **Status:** artifacts ready, nothing deployed yet. **Do not deploy without explicit owner instruction.** First read [Before go-live](#before-go-live): one app-side fix is needed first.

- [Isolation model](#isolation-model)
- [Server profile](#server-profile)
- [Before go-live](#before-go-live)
- [0. Audit the server (read-only)](#0-audit-the-server-read-only)
- [Prerequisites](#prerequisites)
- [1. First-time setup](#1-first-time-setup)
- [Web server](#web-server)
- [Install server files](#install-server-files)
- [Deploy a release](#deploy-a-release)
- [Rollback](#rollback)
- [Backups](#backups)
- [Restore drill](#restore-drill)
- [Monitoring](#monitoring)
- [After a reboot](#after-a-reboot)
- [Changing .env](#changing-env)
- [Telegram webhook](#telegram-webhook)
- [Production .env checklist](#production-env-checklist)
- [Server security findings](#server-security-findings)
- [Open decisions](#open-decisions)

## Isolation model

| Concern | DevStackTips (WordPress) | NunezOrganizer |
|---|---|---|
| Unix user | `nsosu` | **also `nsosu`**. This was the owner's decision: one Webuzo user for every site. PHP for both runs as the same Unix user, so the sites are **not** isolated from each other at the file level. Database, secrets and vhost isolation still apply. |
| Files | `/home/nsosu/sites/devstacktips/public_html` | `/home/nsosu/sites/organizer.nunezserver.online/` (0711; `shared/` 0750), docroot `current/public` |
| PHP | its Webuzo-selected LSPHP | PHP 8.4 (`/usr/local/apps/php84`) selected for this domain in Webuzo |
| Database | `nsosu_dst_prod` / `nsosu_dst` | own schema `nunezorganizer`; users granted on that schema only, `@localhost` and `@127.0.0.1` |
| Secrets | `wp-config.php` | `shared/.env` (0600) and `~/.config/nunezorganizer/backup.my.cnf` (0600) |
| Vhost | Webuzo vhost | its own Webuzo vhost (`webuzo_vh/organizer.nunezserver.online-*`), `restrained 1`, `setUIDMode 2` |

All deploy settings live in `/etc/nunezorganizer/deploy.env`, a copy of `deploy/deploy.env.example`. Every script reads that file.

## Server profile

From the audit on 2026-11-09:

| Item | Value | Effect on deployment |
|---|---|---|
| OS | AlmaLinux 8.10, systemd 239, SELinux disabled | The systemd unit uses only directives systemd 239 supports |
| Web | OpenLiteSpeed 1.9.3 managed by Webuzo. Per-domain vhosts in `/usr/local/lsws/conf/webuzo_vh/<domain>-80` and `-443`. `allowSymbolLink 1`, `restrained 1`, `setUIDMode 2` | Webuzo writes the vhost: **don't hand-edit**. The `current` symlink docroot is allowed |
| PHP | `/usr/local/apps/php84/bin/{php,lsphp}` 8.4.27, all required extensions present | `PHP_BIN`/`LSPHP_BIN` in `deploy.env` |
| DB | MariaDB 10.6.28, socket `/var/lib/mysql/mysql.sock`, `time_zone=SYSTEM` (America/New_York) | See [Before go-live](#before-go-live) |
| TLS | Let's Encrypt via Webuzo; cert for `organizer.nunezserver.online` valid to 2027-02-07 | Webuzo renews it |
| Node | **not installed** | Install Node 22 ([Prerequisites](#prerequisites)) |
| Backup crypto | gpg 2.2.20 present, age missing | Backups default to gpg |
| Mail | Local Exim on 25/465/587 | `MAIL_MAILER=sendmail` |
| Cron | cronie is active. `/var/spool/cron/nsosu` already runs another app's `schedule:run` | This app adds `/etc/cron.d/nunezorganizer` and leaves the existing crontab alone |
| Server timezone | America/New_York | Cron times in `/etc/cron.d/nunezorganizer` are New York time. The app itself uses UTC |

## Before go-live

1. **The database session timezone must be UTC.**
   - The server's MariaDB `time_zone` is `SYSTEM`, which here means America/New_York.
   - The app's `config/database.php` doesn't set a connection timezone, so `TIMESTAMP` columns (Laravel's `timestamps()`, `useCurrent()`) are converted through New York time.
   - UTC values that fall in the spring-forward hour get shifted, and `CURRENT_TIMESTAMP` defaults come out in local time. That breaks ADR 0003.
   - Fix it in the app: add `'timezone' => env('DB_TIMEZONE', '+00:00')` to the `mysql` and `mariadb` connections, with a test.
   - Don't change the server-wide `default_time_zone`: the WordPress sites share this MariaDB.
2. **Merge the deploy PR first.** Releases are cut from `master`, and cron runs `current/deploy/bin/*` from the live release. So `deploy/` must be on `master` before the first release.

## 0. Audit the server (read-only)

```bash
mkdir -p /root/nunez-audit && cd /root/nunez-audit
curl -fsSL -o vps-audit.sh https://raw.githubusercontent.com/rpnunez/nunezorganizer/master/deploy/audit/vps-audit.sh
bash -n vps-audit.sh && chmod +x vps-audit.sh
./vps-audit.sh --domain organizer.nunezserver.online --app-user nsosu
```

The report goes to `nunez-vps-audit-<timestamp>.txt` (mode 600):
- public IPs are masked and secret-looking values are redacted
- it never reads `wp-config.php` or `.env` contents
- re-run it after big server changes

## Prerequisites

Run once, as root.

```bash
# Node.js 22 (needs >= 22.18) for the asset build. AlmaLinux 8 ships it as a dnf module:
dnf module list nodejs
dnf module reset -y nodejs && dnf module install -y nodejs:22/common
node --version          # must print v22.18 or newer
# If AppStream's 22.x is older than 22.18, use NodeSource instead:
#   curl -fsSL https://rpm.nodesource.com/setup_22.x | bash - && dnf install -y nodejs
```

In Webuzo, as `nsosu`:
1. **Domain**: `organizer.nunezserver.online`, path `sites/organizer.nunezserver.online/current/public`, Let's Encrypt ticked. ✅ done 2026-11-09, and the placeholder `current/` was removed.
2. **PHP version** for the domain: **PHP 8.4** (Webuzo's PHP / MultiPHP settings for the domain).
3. **Force HTTPS** for the domain, if Webuzo offers the toggle. [Web server](#web-server) shows how to verify.
4. **Email → DKIM/SPF** for `nunezserver.online`, so verification and password-reset emails from `no-reply@nunezserver.online` aren't marked as spam.

**Backup key, on your Windows PC.** The private key never goes on the server.
1. Install **Gpg4win** and open **Kleopatra**.
2. Click **File → New OpenPGP Key Pair**, enter a name and email, and set a strong passphrase.
3. Right-click the new key → **Export…** and save `nunez-backup-public.asc`. This is the public key only.
4. Back up the full key pair somewhere safe and offline (Kleopatra → **Backup Secret Keys**), such as a password manager or a USB stick. **Without it the backups can't be restored.**
5. Upload `nunez-backup-public.asc` to `/root/` with Webuzo's File Manager.

## 1. First-time setup

Run as root. Lines with `sudo -u nsosu` run as the site user.

```bash
APP=/home/nsosu/sites/organizer.nunezserver.online

# 1. deploy scripts: a throwaway checkout until the first release exists
git clone https://github.com/rpnunez/nunezorganizer.git /root/nunez-deploy
install -d -m 755 /etc/nunezorganizer
install -m 644 /root/nunez-deploy/deploy/deploy.env.example /etc/nunezorganizer/deploy.env

# 2. backup key: import YOUR public key into a dedicated keyring for nsosu
sudo -u nsosu mkdir -p /home/nsosu/.config/nunezorganizer/gnupg
chmod 700 /home/nsosu/.config/nunezorganizer /home/nsosu/.config/nunezorganizer/gnupg
sudo -u nsosu env GNUPGHOME=/home/nsosu/.config/nunezorganizer/gnupg gpg --batch --import < /root/nunez-backup-public.asc
sudo -u nsosu env GNUPGHOME=/home/nsosu/.config/nunezorganizer/gnupg gpg --list-keys --with-colons | awk -F: '/^fpr/ {print $10; exit}'
#    -> put that fingerprint in BACKUP_GPG_RECIPIENT, and set CRON_MAILTO (where alerts go):
nano /etc/nunezorganizer/deploy.env

# 3. directory layout + .env skeleton (generates APP_KEY)
cp -r /root/nunez-deploy /home/nsosu/nunez-deploy && chown -R nsosu:nsosu /home/nsosu/nunez-deploy
sudo -u nsosu /home/nsosu/nunez-deploy/deploy/bin/bootstrap-layout.sh

# 4. database + least-privilege users (review the dry run first)
/root/nunez-deploy/deploy/bin/provision-database.sh            # prints SQL, passwords masked
/root/nunez-deploy/deploy/bin/provision-database.sh --apply    # writes DB_PASSWORD + backup.my.cnf

# 5. validate .env (mail is already set to local sendmail)
sudo -u nsosu /home/nsosu/nunez-deploy/deploy/bin/check-env.sh

# 6. queue worker, cron, logrotate, sudoers (dry run, review, then apply)
/root/nunez-deploy/deploy/bin/render-templates.sh --out /root/nunez-build
/root/nunez-deploy/deploy/bin/install-system-files.sh --build /root/nunez-build
/root/nunez-deploy/deploy/bin/install-system-files.sh --build /root/nunez-build --apply

# 7. first release: runs migrations on the empty DB, then starts the worker
sudo -u nsosu /home/nsosu/nunez-deploy/deploy/bin/release.sh --migrate
systemctl start nunezorganizer-queue

# 8. first backup, and check that everything reports healthy
sudo -u nsosu $APP/current/deploy/bin/backup.sh --label first
sudo -u nsosu $APP/current/deploy/bin/healthcheck.sh --verbose

# 9. create your account (invite-only registration)
cd $APP/current && sudo -u nsosu /usr/local/apps/php84/bin/php artisan nunez:invite you@example.com
rm -rf /home/nsosu/nunez-deploy /root/nunez-deploy /root/nunez-build   # from now on use current/deploy/bin
```

From then on, run every script from `/home/nsosu/sites/organizer.nunezserver.online/current/deploy/bin/`.

## Web server

Webuzo generates and owns the OpenLiteSpeed config:
- `/usr/local/lsws/conf/httpd_config.conf` holds the listeners and maps
- `/usr/local/lsws/conf/webuzo_vh/organizer.nunezserver.online-{80,443}/vhost.conf` holds the per-domain vhost

**Don't edit these by hand;** Webuzo rewrites them. Make changes in the Webuzo UI. The files in `deploy/templates/openlitespeed/` are a reference to compare against, not something to install.

What the 2026-11-09 audit already confirmed for this vhost:
- `vhRoot /home/nsosu/`
- `allowSymbolLink 1`, which the `current` symlink needs
- `restrained 1`
- `setUIDMode 2`, so PHP runs as `nsosu`, the docroot owner
- a Let's Encrypt certificate is issued

Check the generated vhost (read-only):
```bash
grep -nE 'docRoot|vhDomain|lsphp|php8|extUser|extGroup|autoLoadHtaccess|enable|certFile|RewriteRule' \
  /usr/local/lsws/conf/webuzo_vh/organizer.nunezserver.online-443/vhost.conf
```
It should show:
- `docRoot` → `/home/nsosu/sites/organizer.nunezserver.online/current/public`
- the PHP handler path → `/usr/local/apps/php84/bin/lsphp`
- rewrite enabled with `.htaccess` loading (Laravel's `public/.htaccess`)

After the first release:
- `curl -sI http://organizer.nunezserver.online/healthz` → `301` to https (if not, turn on Force HTTPS in Webuzo)
- `curl -s https://organizer.nunezserver.online/healthz` → `200`
- `curl -s -o /dev/null -w '%{http_code}' https://organizer.nunezserver.online/.env` → `404`

Security headers (HSTS, nosniff, frame-deny) aren't added by the Webuzo vhost. OpenLiteSpeed's `.htaccess` doesn't support `Header` directives, so they have to come from the app (a small Laravel middleware). That's tracked as an app follow-up.

## Install server files

```bash
cd /home/nsosu/sites/organizer.nunezserver.online/current        # or the throwaway checkout on first setup
deploy/bin/render-templates.sh --out /root/nunez-build
less -R /root/nunez-build/*/*                      # review
deploy/bin/install-system-files.sh --build /root/nunez-build           # dry run with diffs
deploy/bin/install-system-files.sh --build /root/nunez-build --apply   # install
```

This installs:
- the queue worker (`/etc/systemd/system/nunezorganizer-queue.service`, enabled at boot, or the supervisor program if `QUEUE_SUPERVISOR=supervisor`)
- `/etc/cron.d/nunezorganizer`: `schedule:run` every minute, the backup at 03:17, and the health check every 5 minutes
- `/etc/logrotate.d/nunezorganizer`
- `/etc/sudoers.d/nunezorganizer`, which is checked with `visudo -c` before install

Anything it replaces is kept as `*.bak.<timestamp>`.

## Deploy a release

```bash
sudo -u nsosu /home/nsosu/sites/organizer.nunezserver.online/current/deploy/bin/release.sh [--ref <branch|tag|sha>]
```

What it does, in order:
1. Takes a lock, validates `.env`, and checks PHP ≥ 8.4 with the required extensions and Node ≥ 22.18.
2. Fetches into `repo.git`, then exports the ref into `releases/<UTC ts>-<sha>`. Releases contain no `.git`.
3. Links `shared/.env` and `shared/storage`.
4. Runs `composer install --no-dev -o`, then `npm ci && npm run build`, then deletes `node_modules`.
5. **Migration gate.** If migrations are pending, it stops *before* switching and saves the SQL preview to `shared/logs/pending-migrations-<release>.sql.txt`. To go ahead:
   1. Read the preview.
   2. Take a backup: `deploy/bin/backup.sh --label pre-migrate`.
   3. Re-run with `--migrate`. Add `--maintenance` to show the maintenance page while migrations run.
6. Runs `artisan optimize`, then swaps the `current` symlink atomically (`rename(2)`).
7. Restarts OpenLiteSpeed so LSPHP drops cached paths of the old release, then runs `queue:restart`.
8. Checks `/healthz` and `/readyz`. If either fails and no migrations ran, it **rolls back automatically** to the previous release.
9. Prunes old releases down to `KEEP_RELEASES`. It never deletes the current or previous release.

Every action is appended to `shared/logs/releases.log`.

**Migration discipline:** the new code runs migrations while the old release is still serving. Keep migrations additive (expand → deploy → contract in a later release), so the previous release still works on the new schema and rollback stays safe.

## Rollback

```bash
sudo -u nsosu .../current/deploy/bin/rollback.sh --list
sudo -u nsosu .../current/deploy/bin/rollback.sh            # to the previous release
sudo -u nsosu .../current/deploy/bin/rollback.sh --to <release-dir>
```

Rollback switches code only. If the release you're leaving ran migrations (`migrated=1` in `releases.log`; the script warns), check before rolling back:
- **Additive migrations** (new tables or nullable columns): a code rollback is safe. Leave the schema alone.
- **Destructive or renaming migrations:** either roll forward with a fix, or:
  1. Put the app in maintenance: `artisan down`.
  2. Run `php artisan migrate:rollback --step=N` **from the newer release's directory**, which is the code that knows how to undo it.
  3. Run `rollback.sh`, then `artisan up`.
- **Data loss:** restore the `pre-migrate` backup ([Restore drill](#restore-drill), with `--into-production`).

## Backups

Nightly at 03:17 server time (New York), `backup.sh` writes three files to `/home/nsosu/backups/nunezorganizer` (0700):

| File | Contents |
|---|---|
| `nunezorganizer-<ts>.sql.gz.gpg` | `mariadb-dump --single-transaction` of the app DB only, gzipped and encrypted to your public key |
| `nunezorganizer-<ts>.files.tar.gz.gpg` | `shared/.env` and `shared/storage/app` |
| `nunezorganizer-<ts>.sha256` | checksums of the two files above |

- **No plaintext ever touches the disk.** The dump is streamed through gzip and gpg.
- The server's keyring (`/home/nsosu/.config/nunezorganizer/gnupg`) holds **only your public key**. `backup.sh` refuses to run if the matching private key is ever imported there.
- The dump user has `SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT` on `nunezorganizer` only. Its credentials sit in an option file, never in argv.
- Retention is `BACKUP_RETENTION_DAYS` (14).
- **Off-site copies:** Webuzo's Backuply already runs on this server. Either add `/home/nsosu/backups/nunezorganizer` to its backup set, or set `BACKUP_OFFSITE_HOOK` to a script that copies the files elsewhere. Backups that never leave the box don't help if the VPS itself is lost.
- Manual backup: `sudo -u nsosu .../current/deploy/bin/backup.sh --label <why>`.

`age` is also supported (`BACKUP_ENCRYPTION=age`), but it isn't installed on this server.

## Restore drill

Do this at least once before the pilot (an acceptance criterion of #21), and after any change to backups.

**On your Windows PC, which has the private key:**
1. Download one backup set (`.sql.gz.gpg`, `.files.tar.gz.gpg`, `.sha256`) with Webuzo's File Manager.
2. In Kleopatra, choose **Decrypt/Verify** on the `.sql.gz.gpg` file. You get a `.sql.gz` you can open with 7-Zip and inspect.

That proves the backup can be decrypted. Then do a full restore into a scratch database on the server.

**On the server, as root, with the private key present only for the drill:**
```bash
# import the private key into a TEMPORARY keyring, never nsosu's backup keyring
export GNUPGHOME=$(mktemp -d) && chmod 700 "$GNUPGHOME"
gpg --import /root/nunez-backup-secret.asc          # uploaded via File Manager for the drill
APP=/home/nsosu/sites/organizer.nunezserver.online
SET=$(ls -1t /home/nsosu/backups/nunezorganizer/*.sha256 | head -n1); SET=${SET%.sha256}
NUNEZ_DEPLOY_CONFIG=/etc/nunezorganizer/deploy.env $APP/current/deploy/bin/restore.sh --set "$SET" --identity gpg --verify-only
NUNEZ_DEPLOY_CONFIG=/etc/nunezorganizer/deploy.env $APP/current/deploy/bin/restore.sh --set "$SET" --identity gpg --files-to /root/restore-drill-files
mariadb -e 'DROP DATABASE nunezorganizer_restore_test'
shred -u /root/nunez-backup-secret.asc; rm -rf "$GNUPGHOME" /root/restore-drill-files; unset GNUPGHOME
```

`restore.sh` checks checksums first. It restores into `nunezorganizer_restore_test` unless you pass `--into-production --yes-overwrite-production` (run `artisan down` first). Files are only extracted into an empty directory you name, never in place.

Record each drill (date, backup used, table counts, problems) in the release checklist (#23).

## Monitoring

- `/healthz` (liveness) and `/readyz` (DB readiness) are public and return no details.
- `healthcheck.sh` runs every 5 minutes from cron and checks:
  - the `current` release is intact
  - `/healthz` and `/readyz` return 200
  - the queue worker is active
  - free disk is at least `HEALTHCHECK_MIN_FREE_MB`
  - the last successful backup is at most `BACKUP_MAX_AGE_HOURS` old
- It prints (so cron mails `CRON_MAILTO`) only when the state changes, with an hourly reminder while it stays unhealthy. **Set `CRON_MAILTO`, and make sure the server can send mail**, or alerts go nowhere.
- App-level alarms (`nunez:health`, for stuck notifications and scheduler lag) arrive with M4 (#14/#17). Add that command to cron when it exists.

## After a reboot

Nothing to do if setup is complete. Check:
- `systemctl status nunezorganizer-queue`: enabled, `Restart=always`
- cron is running (it drives `schedule:run`)
- OLS and MariaDB are enabled
- `.../current/deploy/bin/healthcheck.sh --verbose` reports healthy

## Changing .env

```bash
sudo -u nsosu nano /home/nsosu/sites/organizer.nunezserver.online/shared/.env
sudo -u nsosu .../current/deploy/bin/check-env.sh
cd /home/nsosu/sites/organizer.nunezserver.online/current && sudo -u nsosu /usr/local/apps/php84/bin/php artisan config:cache && sudo -u nsosu /usr/local/apps/php84/bin/php artisan queue:restart
/usr/local/lsws/bin/lswsctrl restart
```

To rotate DB passwords, run `provision-database.sh --apply --rotate` as root, then the `config:cache` steps above.

## Telegram webhook

This becomes relevant once M4 ships (#15/#16). After each change of domain, `TELEGRAM_WEBHOOK_ROUTE_KEY` or `TELEGRAM_WEBHOOK_SECRET`, re-register the webhook with the artisan command that M4 adds. The bot token stays in `shared/.env`, and the log redaction from #6 masks it.

## Production .env checklist

`check-env.sh` enforces most of these:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a `base64:` `APP_KEY`, an `https://` `APP_URL`
- [ ] `.env` is mode 600 and owned by `nsosu`
- [ ] `DB_*`: not root, not the Docker values (`mariadb`/`secret`); `DB_HOST=127.0.0.1`
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax`
- [ ] `LOG_STACK=single`, `LOG_LEVEL=warning` (logrotate handles rotation)
- [ ] `NUNEZ_REGISTRATION_MODE=invite`
- [ ] mail works: `MAIL_MAILER=sendmail` through local Exim, with DKIM/SPF set for the From domain (password reset and email verification depend on it)
- [ ] Trusted proxies: not needed while OLS terminates TLS directly. If Cloudflare or another proxy is put in front, configure `trustProxies` in `bootstrap/app.php` for the proxy ranges only, never `*`.
- [ ] HTTPS-only redirect and HSTS are active (see [Web server](#web-server))

## Server security findings

The audit turned these up. They're outside this app, but they affect every site on the box. They're listed for the owner and not changed by any script here.

| Finding | Risk | Suggested fix |
|---|---|---|
| MariaDB listens on `0.0.0.0:3306` and `[::]:3306`, and firewalld isn't running | The database is reachable from the internet. Users with host `%` (`nsosu_la`, `nsosu_dst_dev_user`, anonymous `''@'%'` on `test`) can be brute-forced | Set `bind-address=127.0.0.1` if nothing remote needs the DB, or firewall 3306. Drop the anonymous users and the `test` DB |
| `PermitRootLogin yes` and `PasswordAuthentication yes` in sshd | Root password brute force (fail2ban only has an `exim-auth` jail) | Key-only root login (`PermitRootLogin prohibit-password`, `PasswordAuthentication no`) once your key works, plus an sshd fail2ban jail |
| No swap, 6 GB RAM, `innodb_buffer_pool_size=4G` | The kernel may kill processes when memory runs out under load | Add a 2 GB swap file, or lower the buffer pool |

## Open decisions

| Decision | Needed for | Status |
|---|---|---|
| Subdomain | vhost, TLS, `APP_URL` | **decided:** `organizer.nunezserver.online` |
| Unix user | file isolation | **decided:** `nsosu` (shared with the other sites) |
| PHP 8.4 path | `PHP_BIN`, `LSPHP_BIN` | **from audit:** `/usr/local/apps/php84/bin/*` |
| Backup encryption | `BACKUP_ENCRYPTION` | **from audit:** gpg (age not installed) |
| Mail | `MAIL_*` | **from audit:** local Exim via sendmail; DKIM/SPF to confirm |
| Staging vhost? | pre-prod releases | none; the restore drill uses `nunezorganizer_restore_test` |
| systemd or supervisor | queue worker | systemd |
| Alert email | `CRON_MAILTO` | **owner to set** in `/etc/nunezorganizer/deploy.env` |
| Off-site backup target | Backuply or `BACKUP_OFFSITE_HOOK` | **owner to decide** |
| DB session timezone | ADR 0003 correctness | **app fix needed** ([Before go-live](#before-go-live)) |
