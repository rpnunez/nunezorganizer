# NunezOrganizer production runbook

Operating NunezOrganizer on the DevStackTips VPS, fully isolated from WordPress (issue #21).

> **Status:** artifacts only. Nothing here has been run on the VPS yet. Values marked **TBD** wait on the VPS audit and the owner's decisions. **Do not deploy without explicit owner instruction.**

- [Isolation model](#isolation-model)
- [0. Audit the server (read-only)](#0-audit-the-server-read-only)
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
- [Open decisions](#open-decisions)

## Isolation model

| Concern | WordPress | NunezOrganizer |
|---|---|---|
| Unix user | existing WP user | dedicated `nunezorg`; no sudo except one `lswsctrl restart` rule |
| Files | WP docroot | `/home/nunezorg/nunezorganizer` (0750), docroot `current/public` |
| PHP | WP's LSPHP handler | its own LSAPI external app running as `nunezorg` (PHP 8.4) |
| Database | WP schema/user | own schema `nunezorganizer`; users granted on that schema only, `@localhost`/`@127.0.0.1` |
| Secrets | `wp-config.php` | `shared/.env` (0600) and `~/.config/nunezorganizer/backup.my.cnf` (0600) |
| Vhost | WP vhost | own OLS vhost and domain map; `restrained 1` keeps scripts inside `vhRoot` |

All config lives in `/etc/nunezorganizer/deploy.env` (copy of `deploy/deploy.env.example`). Every script reads it.

## 0. Audit the server (read-only)

```bash
curl -fsSLO https://raw.githubusercontent.com/rpnunez/nunezorganizer/<branch>/deploy/audit/vps-audit.sh
less vps-audit.sh            # it is read-only; check that for yourself
sudo bash vps-audit.sh --domain <planned-subdomain>
```

It writes `nunez-vps-audit-<timestamp>.txt` (mode 600). Public IPs are masked and secret-looking values are redacted. It never reads `wp-config.php` or `.env` contents. Review the report, then send it back. It answers the TBDs: PHP 8.4 path, how Webuzo lays out OLS vhosts, MariaDB version, Node/Composer, cron and supervisor, TLS tooling, and whether the subdomain already points at the server.

## 1. First-time setup

Run as root unless the step says `sudo -u nunezorg`.

```bash
# 1. dedicated user (if Webuzo doesn't create one when you add the domain)
useradd --create-home --shell /bin/bash nunezorg
chmod 750 /home/nunezorg

# 2. deploy scripts: a throwaway checkout is enough until the first release exists
git clone https://github.com/rpnunez/nunezorganizer.git /root/nunez-deploy
install -d -m 755 /etc/nunezorganizer
install -m 644 /root/nunez-deploy/deploy/deploy.env.example /etc/nunezorganizer/deploy.env
$EDITOR /etc/nunezorganizer/deploy.env        # fill in the TBDs from the audit

# 3. backup encryption: create the key on YOUR machine, copy only the public key here
#    (on your laptop)  age-keygen -o nunez-backup.key   -> prints "Public key: age1..."
echo 'age1...yourpublickey' > /etc/nunezorganizer/backup-recipients.txt
chmod 644 /etc/nunezorganizer/backup-recipients.txt

# 4. directory layout + .env skeleton (generates APP_KEY)
cp -r /root/nunez-deploy /home/nunezorg/nunez-deploy && chown -R nunezorg: /home/nunezorg/nunez-deploy
sudo -u nunezorg /home/nunezorg/nunez-deploy/deploy/bin/bootstrap-layout.sh

# 5. database + least-privilege users (review the dry run first)
/root/nunez-deploy/deploy/bin/provision-database.sh            # prints SQL, passwords masked
/root/nunez-deploy/deploy/bin/provision-database.sh --apply    # writes DB_PASSWORD + backup.my.cnf

# 6. fill MAIL_* in /home/nunezorg/nunezorganizer/shared/.env, then validate
sudo -u nunezorg /home/nunezorg/nunez-deploy/deploy/bin/check-env.sh

# 7. web server (see below), TLS, then system files (see below)

# 8. first release (runs migrations on an empty DB)
sudo -u nunezorg /home/nunezorg/nunez-deploy/deploy/bin/release.sh --migrate
systemctl start nunezorganizer-queue
rm -rf /home/nunezorg/nunez-deploy /root/nunez-deploy   # from now on use current/deploy/bin
```

From then on, run every script from `/home/nunezorg/nunezorganizer/current/deploy/bin/`.

## Web server

Webuzo manages `httpd_config.conf` and can overwrite hand edits. Do it in this order:

1. **Preferred:** add the subdomain in Webuzo under the `nunezorg` user, choose PHP 8.4, and issue the TLS certificate there.
2. Set the docroot to `/home/nunezorg/nunezorganizer/current/public`.
3. Compare the vhost Webuzo generated with `deploy/build/openlitespeed/vhconf.conf` (render it first; see the next section). Make sure the generated vhost has:
   - the PHP handler runs as `nunezorg` (`extUser`/`extGroup` or `setUIDMode 2`), not `nobody` and not the WP user
   - `allowSymbolLink 1` (the docroot is reached through the `current` symlink)
   - rewrite enabled with `.htaccess` loading (Laravel's `public/.htaccess`)
   - an HTTP→HTTPS redirect that still lets `/.well-known/acme-challenge/` through
   - the security headers (HSTS, nosniff, frame DENY, referrer policy)
4. Only if Webuzo can't express one of these, add it by hand from `httpd_config.snippet.conf` / `vhconf.conf`. Back up the file first, then run `/usr/local/lsws/bin/lswsctrl restart`.
5. Check:
   - `curl -I http://<domain>/healthz` → 301 to https
   - `curl https://<domain>/healthz` → 200
   - `curl https://<domain>/.env` → 404

The dotfile-deny context uses a PCRE lookahead. If OLS rejects it, delete that context: the docroot is `public/`, so `.env` lives outside it anyway.

## Install server files

```bash
cd /home/nunezorg/nunezorganizer/current        # or the throwaway checkout on first setup
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
sudo -u nunezorg /home/nunezorg/nunezorganizer/current/deploy/bin/release.sh [--ref <branch|tag|sha>]
```

What it does, in order:
1. Takes a lock, validates `.env` and checks PHP ≥ 8.4 plus the required extensions.
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
sudo -u nunezorg .../current/deploy/bin/rollback.sh --list
sudo -u nunezorg .../current/deploy/bin/rollback.sh            # to the previous release
sudo -u nunezorg .../current/deploy/bin/rollback.sh --to <release-dir>
```

Rollback switches code only. If the release you're leaving ran migrations (`migrated=1` in `releases.log`; the script warns), check before rolling back:
- **Additive migrations** (new tables or nullable columns): a code rollback is safe. Leave the schema alone.
- **Destructive or renaming migrations:** either roll forward with a fix, or:
  1. Put the app in maintenance: `artisan down`.
  2. Run `php artisan migrate:rollback --step=N` **from the newer release's directory**, which is the code that knows how to undo it.
  3. Run `rollback.sh`, then `artisan up`.
- **Data loss:** restore the `pre-migrate` backup ([Restore drill](#restore-drill), with `--into-production`).

## Backups

Nightly at 03:17 (cron), `backup.sh` writes three files to `/home/nunezorg/backups` (0700):

| File | Contents |
|---|---|
| `nunezorganizer-<ts>.sql.gz.age` | `mariadb-dump --single-transaction` of the app DB only, gzipped and age-encrypted |
| `nunezorganizer-<ts>.files.tar.gz.age` | `shared/.env` and `shared/storage/app` |
| `nunezorganizer-<ts>.sha256` | checksums of the two files above |

- **No plaintext ever touches the disk.** The dump is streamed through gzip and age.
- The server holds **only the public key**. `backup.sh` refuses to run if the recipients file contains a private key.
- The dump user has `SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT` on the app schema only. Its credentials sit in an option file, never in argv.
- Retention is `BACKUP_RETENTION_DAYS` (14). Set `BACKUP_OFFSITE_HOOK` to a script (for example rclone to object storage) to copy each new backup off the box. Backups that never leave the box don't protect against losing the VPS.
- Manual backup: `backup.sh --label <why>`.

## Restore drill

Do this at least once before the pilot (an acceptance criterion of #21), and after any change to backups.

Preferred: run it on your own machine, which has the private key:

```bash
scp nunezorg@vps:backups/nunezorganizer-<ts>.* .
deploy/bin/restore.sh --set ./nunezorganizer-<ts> --identity ~/nunez-backup.key --verify-only
deploy/bin/restore.sh --set ./nunezorganizer-<ts> --identity ~/nunez-backup.key --files-to ./restored-files
# restores into nunezorganizer_restore_test on your local MariaDB and prints table row counts
```

On the server, as root, with the key copied over just for the drill and deleted afterwards:

```bash
install -m 600 /dev/stdin /root/drill.key   # paste the key, then Ctrl-D
.../current/deploy/bin/restore.sh --set /home/nunezorg/backups/nunezorganizer-<ts> --identity /root/drill.key
shred -u /root/drill.key
mariadb -e 'DROP DATABASE nunezorganizer_restore_test'
```

`restore.sh` checks checksums first. It restores into `<DB_NAME>_restore_test` unless you pass `--into-production --yes-overwrite-production` (run `artisan down` first). Files are only extracted into an empty directory you name, never in place.

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
sudo -u nunezorg $EDITOR /home/nunezorg/nunezorganizer/shared/.env
sudo -u nunezorg .../current/deploy/bin/check-env.sh
cd /home/nunezorg/nunezorganizer/current && sudo -u nunezorg php artisan config:cache && sudo -u nunezorg php artisan queue:restart
/usr/local/lsws/bin/lswsctrl restart
```

To rotate DB passwords, run `provision-database.sh --apply --rotate` as root, then the `config:cache` steps above.

## Telegram webhook

This becomes relevant once M4 ships (#15/#16). After each change of domain, `TELEGRAM_WEBHOOK_ROUTE_KEY` or `TELEGRAM_WEBHOOK_SECRET`, re-register the webhook with the artisan command that M4 adds. The bot token stays in `shared/.env`, and the log redaction from #6 masks it.

## Production .env checklist

`check-env.sh` enforces most of these:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a `base64:` `APP_KEY`, an `https://` `APP_URL`
- [ ] `.env` is mode 600 and owned by `nunezorg`
- [ ] `DB_*`: not root, not the Docker values (`mariadb`/`secret`); `DB_HOST=127.0.0.1`
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax`
- [ ] `LOG_STACK=single`, `LOG_LEVEL=warning` (logrotate handles rotation)
- [ ] `NUNEZ_REGISTRATION_MODE=invite`
- [ ] working SMTP in `MAIL_*` (password reset and email verification depend on it)
- [ ] Trusted proxies: not needed while OLS terminates TLS directly. If Cloudflare or another proxy is put in front, configure `trustProxies` in `bootstrap/app.php` for the proxy ranges only, never `*`.
- [ ] HTTPS-only redirect and HSTS are active (see [Web server](#web-server))

## Open decisions

| Decision | Needed for | Default in artifacts |
|---|---|---|
| Subdomain | vhost, TLS, `APP_URL` | `organizer.example.com` placeholder |
| Staging vhost? | restore drill target, pre-prod releases | none; the drill uses `<db>_restore_test` |
| VPS PHP 8.4 path | `PHP_BIN`, `LSPHP_BIN` | `/usr/local/lsws/lsphp84/bin/*` |
| systemd or supervisor | queue worker | systemd |
| Alert email | `CRON_MAILTO` | empty (no alerts) |
| Off-site backup target | `BACKUP_OFFSITE_HOOK` | none |
| SMTP provider | `MAIL_*` | empty |
