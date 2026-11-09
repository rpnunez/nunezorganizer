# /etc/cron.d/{{APP_SLUG}} — NunezOrganizer scheduled work (issue #21).
# Fields: minute hour day month weekday USER command. Times are server time.
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
MAILTO="{{CRON_MAILTO}}"

# Laravel scheduler (reviews, notification dispatch, carryover). Must run every minute.
* * * * * {{APP_USER}} cd {{APP_ROOT}}/current && {{PHP_BIN}} artisan schedule:run >> /dev/null 2>&1

# Encrypted nightly backup at 03:17. Output goes to the log; failures also reach MAILTO.
17 3 * * * {{APP_USER}} {{APP_ROOT}}/current/deploy/bin/backup.sh >> {{APP_ROOT}}/shared/logs/backup.log 2>&1 || echo "{{APP_SLUG}} backup FAILED; see {{APP_ROOT}}/shared/logs/backup.log"

# Health check every 5 minutes. Prints (and so mails) only when the state changes, plus an hourly reminder.
*/5 * * * * {{APP_USER}} {{APP_ROOT}}/current/deploy/bin/healthcheck.sh
