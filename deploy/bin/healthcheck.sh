#!/usr/bin/env bash
# Infrastructure health check for cron (issue #21, step 6). Run as the app user.
#
#   healthcheck.sh [--verbose]
#
# Checks: current symlink, /healthz + /readyz, queue worker running, free disk,
# backup freshness. Silent when healthy and when a failure was already reported
# within HEALTHCHECK_REALERT_MINUTES, so cron's MAILTO only mails on change.
# Exit code is always non-zero while unhealthy.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

VERBOSE=0
[[ "${1:-}" == "--verbose" ]] && VERBOSE=1

load_config
problems=()

# 1. release
if [[ ! -L "$CURRENT_LINK" || ! -f "$CURRENT_LINK/artisan" ]]; then
  problems+=("current release missing or broken: $CURRENT_LINK")
fi

# 2. HTTP
base="${APP_URL%/}"
for path in healthz readyz; do
  code="$(http_status "$base/$path")"
  [[ "$code" == "200" ]] || problems+=("/$path returned ${code:-none}")
done

# 3. queue worker
case "${QUEUE_SUPERVISOR:-systemd}" in
  systemd)
    state="$(systemctl is-active "${QUEUE_SERVICE}.service" 2>/dev/null || true)"
    [[ "$state" == "active" ]] || problems+=("queue worker ${QUEUE_SERVICE}.service is ${state:-unknown}")
    ;;
  supervisor)
    if ! supervisorctl status "${QUEUE_SERVICE}:*" 2>/dev/null | grep -q RUNNING; then
      problems+=("queue worker ${QUEUE_SERVICE} not RUNNING in supervisor")
    fi
    ;;
esac

# 4. disk
free_mb="$(df -Pm "$APP_ROOT" | awk 'NR==2 {print $4}')"
if [[ -n "$free_mb" ]] && (( free_mb < ${HEALTHCHECK_MIN_FREE_MB:-1024} )); then
  problems+=("low disk: ${free_mb}MB free on $APP_ROOT")
fi

# 5. backups
marker="$BACKUP_DIR/.last-success"
if [[ ! -f "$marker" ]]; then
  problems+=("no successful backup recorded ($marker)")
else
  age_h=$(( ($(date +%s) - $(stat -c %Y "$marker")) / 3600 ))
  (( age_h <= ${BACKUP_MAX_AGE_HOURS:-26} )) || problems+=("last successful backup is ${age_h}h old")
fi

state_file="$DEPLOY_LOG_DIR/.healthcheck.state"
mkdir -p "$DEPLOY_LOG_DIR"
now="$(date +%s)"

if [[ ${#problems[@]} -eq 0 ]]; then
  if [[ -f "$state_file" ]]; then
    echo "RECOVERED: $APP_SLUG is healthy again ($(date -u +%FT%TZ))"
    rm -f "$state_file"
  fi
  [[ $VERBOSE -eq 1 ]] && log "healthy"
  exit 0
fi

summary="$(printf '%s\n' "${problems[@]}")"
last_alert=0; last_summary=""
if [[ -f "$state_file" ]]; then
  last_alert="$(head -n1 "$state_file")"
  last_summary="$(tail -n +2 "$state_file")"
fi
realert=$(( ${HEALTHCHECK_REALERT_MINUTES:-60} * 60 ))
if [[ $VERBOSE -eq 1 || "$summary" != "$last_summary" || $(( now - last_alert )) -ge $realert ]]; then
  echo "UNHEALTHY: $APP_SLUG ($(date -u +%FT%TZ))"
  printf ' - %s\n' "${problems[@]}"
  printf '%s\n%s\n' "$now" "$summary" > "$state_file"
fi
exit 1
