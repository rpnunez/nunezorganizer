# deploy/

Production deployment tooling for NunezOrganizer (issue #21): zero-downtime releases, rollback, encrypted backups and monitoring, on any Linux host. Start with **[docs/install.md](../docs/install.md)**. [docs/runbook.md](../docs/runbook.md) is a worked example on a Webuzo + OpenLiteSpeed host. For a simple single-directory install, `php setup.php --env=production` at the repo root is enough.

**Artifacts only. Nothing here deploys anything unless an operator runs it on the server.**

```
deploy/
├── deploy.env.example          # all settings → /etc/nunezorganizer/deploy.env (no secrets)
├── audit/vps-audit.sh          # read-only server audit; run first, send back the report
├── bin/
│   ├── bootstrap-layout.sh     # app user: dirs + shared/.env skeleton (APP_KEY generated)
│   ├── provision-database.sh   # root: DB + least-privilege app/backup users (dry run by default)
│   ├── check-env.sh            # validate production .env without printing values
│   ├── render-templates.sh     # templates/*.tpl → build/ with values from deploy.env
│   ├── install-system-files.sh # root: systemd/supervisor, cron, logrotate, sudoers (dry run by default)
│   ├── release.sh              # app user: cut + activate a release (migration gate, auto-rollback)
│   ├── rollback.sh             # app user: switch current to an earlier release
│   ├── backup.sh               # app user: gpg/age-encrypted DB + files backup with retention
│   ├── restore.sh              # verify / restore a backup into a drill DB
│   ├── healthcheck.sh          # cron health check (HTTP, queue, disk, backup age)
│   └── lib.sh                  # shared helpers
├── templates/
│   ├── nginx/                  # nginx vhost (HTTPS, headers, $realpath_root for symlinked releases)
│   ├── apache/                 # Apache 2.4 vhost (mod_proxy_fcgi)
│   ├── php-fpm/                # PHP-FPM pool running as the app user
│   ├── openlitespeed/          # vhconf.conf + httpd_config snippet (applied by hand / via a panel)
│   ├── systemd/                # queue worker unit (hardened)
│   ├── supervisor/             # alternative queue worker config
│   ├── cron/                   # schedule:run, nightly backup, health check
│   ├── logrotate/
│   ├── sudoers/                # only: app user may run WEB_RELOAD_PRIVILEGED_CMD
│   └── env/production.env.tpl  # production .env skeleton
└── tests/
    ├── run.sh                  # offline test suite (stubs for php/composer/npm/db/curl)
    └── setup.sh                # setup.php tests (run by run.sh)
```

## Tests

```bash
bash deploy/tests/run.sh
```

The tests need no server, PHP app or database. They lint every script with shellcheck and render every template (the unit is checked with `systemd-analyze verify`, the sudoers file with `visudo -c`). Then they exercise these, using stubbed tools:
- full release flow: migration gate, maintenance mode, health-check auto-rollback, the lock, pruning
- rollback
- gpg- and age-encrypted backup and restore, including tamper detection, refusal of private keys, and retention
- `.env` validation
- health-check alert suppression
- the audit script

`.github/workflows/deploy-scripts.yml` runs the suite on changes under `deploy/`.
