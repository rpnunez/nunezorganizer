#!/usr/bin/env bash
# Point `current` back at an earlier release (issue #21, step 7). Run as the app user.
#
#   rollback.sh                 # previous release (the one before current)
#   rollback.sh --to <release>  # a specific directory name under releases/
#   rollback.sh --list
#
# Code only: this does NOT roll back migrations. If the release you're leaving
# ran migrations, read docs/runbook.md#rollback first.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

TARGET=""
LIST=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --to) TARGET="${2:?--to needs a release name}"; shift 2 ;;
    --list) LIST=1; shift ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config
require_app_user
use_php_path

CUR="$(current_release)"
mapfile -t all < <(release_list)

if [[ $LIST -eq 1 ]]; then
  for r in "${all[@]}"; do
    printf '%s %s\n' "$([[ "$r" == "$CUR" ]] && echo '*' || echo ' ')" "$r"
  done
  exit 0
fi

exec 9>"$APP_ROOT/.deploy.lock"
flock -n 9 || die "another release/rollback is running"

if [[ -z "$TARGET" ]]; then
  for (( i = ${#all[@]} - 1; i >= 0; i-- )); do
    if [[ "${all[$i]}" == "$CUR" ]]; then
      for (( j = i - 1; j >= 0; j-- )); do
        [[ -e "$RELEASES_DIR/${all[$j]}/.failed-release" ]] && continue
        TARGET="${all[$j]}"
        break
      done
      break
    fi
  done
  [[ -n "$TARGET" ]] || die "no release older than ${CUR:-<none>} to roll back to"
fi
[[ "$TARGET" == "$CUR" ]] && die "$TARGET is already current"
[[ -d "$RELEASES_DIR/$TARGET" ]] || die "release not found: $TARGET"
[[ ! -e "$RELEASES_DIR/$TARGET/.failed-release" ]] || die "$TARGET is a failed release (kept only for migrate:rollback); pick another"

if grep -q "ACTIVATED $CUR .*migrated=1" "$DEPLOY_LOG_DIR/releases.log" 2>/dev/null; then
  warn "$CUR ran migrations when it was released. Rolling back code only; the schema stays as it is."
  warn "That is safe only if the migrations were additive (see docs/runbook.md#rollback)."
fi

log "rolling back: $CUR -> $TARGET"
artisan "$RELEASES_DIR/$TARGET" optimize
switch_current "$TARGET"
record "ROLLBACK $CUR -> $TARGET"
reload_web
restart_queue "$CURRENT_LINK"

sleep "${HEALTHCHECK_DELAY_SECONDS:-3}"
if check_health; then
  log "health OK; $TARGET is live"
else
  die "$TARGET is live but failing its health check"
fi
