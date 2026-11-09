# deploy/

Deployment artifacts for running NunezOrganizer on the DevStackTips VPS, isolated from WordPress (issue #21). The procedure is in **[docs/runbook.md](../docs/runbook.md)**.

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
│   ├── backup.sh               # app user: encrypted DB + files backup with retention
│   ├── restore.sh              # verify / restore a backup into a drill DB
│   ├── healthcheck.sh          # cron health check (HTTP, queue, disk, backup age)
│   └── lib.sh                  # shared helpers
├── templates/
│   ├── openlitespeed/          # vhconf.conf + httpd_config snippet (applied by hand / via Webuzo)
│   ├── systemd/                # queue worker unit (hardened)
│   ├── supervisor/             # alternative queue worker config
│   ├── cron/                   # schedule:run, nightly backup, health check
│   ├── logrotate/
│   ├── sudoers/                # only: app user may run `lswsctrl restart`
│   └── env/production.env.tpl  # production .env skeleton
└── tests/run.sh                # offline test suite (stubs for php/composer/npm/db/curl)
```

## Tests

```bash
bash deploy/tests/run.sh
```

The tests need no server, PHP app or database. They lint every script with shellcheck and render every template (the unit is checked with `systemd-analyze verify`, the sudoers file with `visudo -c`). Then they exercise these, using stubbed tools:
- full release flow: migration gate, maintenance mode, health-check auto-rollback, the lock, pruning
- rollback
- age-encrypted backup and restore, including tamper detection and retention
- `.env` validation
- health-check alert suppression
- the audit script

`.github/workflows/deploy-scripts.yml` runs the suite on changes under `deploy/`.
