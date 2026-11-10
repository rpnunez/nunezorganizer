#!/usr/bin/env bash
# Install the rendered system files (queue worker, cron, logrotate, sudoers). Run as root.
#
#   install-system-files.sh [--build DIR]            # dry run: show what would change (diff)
#   install-system-files.sh [--build DIR] --apply    # install, keeping a .bak of anything replaced
#
# Renders first with:  deploy/bin/render-templates.sh --out DIR
#
# OpenLiteSpeed config is deliberately NOT installed by this script: Webuzo
# manages httpd_config.conf and may overwrite manual edits. Apply the
# openlitespeed/ files by hand or through Webuzo (docs/runbook.md#web-server).

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

BUILD="$DEPLOY_DIR/build"; APPLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --build) BUILD="${2:?}"; shift 2 ;;
    --apply) APPLY=1; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config
[[ -d "$BUILD" ]] || die "$BUILD not found; run render-templates.sh first"
[[ $APPLY -eq 0 ]] || require_root

# source (relative to BUILD) | destination | mode
plan=(
  "cron/nunezorganizer.cron|/etc/cron.d/${APP_SLUG}|0644"
  "logrotate/nunezorganizer|/etc/logrotate.d/${APP_SLUG}|0644"
  "sudoers/nunezorganizer|/etc/sudoers.d/${APP_SLUG}|0440"
)
case "${QUEUE_SUPERVISOR:-systemd}" in
  systemd) plan+=("systemd/nunezorganizer-queue.service|/etc/systemd/system/${QUEUE_SERVICE}.service|0644") ;;
  supervisor)
    sup_dir=/etc/supervisord.d; [[ -d /etc/supervisor/conf.d ]] && sup_dir=/etc/supervisor/conf.d
    ext=ini; [[ "$sup_dir" == /etc/supervisor/conf.d ]] && ext=conf
    plan+=("supervisor/nunezorganizer-queue.conf|$sup_dir/${QUEUE_SERVICE}.$ext|0644") ;;
  *) die "QUEUE_SUPERVISOR must be systemd or supervisor" ;;
esac

changed=0
for entry in "${plan[@]}"; do
  IFS='|' read -r src dest mode <<<"$entry"
  [[ -f "$BUILD/$src" ]] || die "missing rendered file $BUILD/$src"
  if [[ -f "$dest" ]] && cmp -s "$BUILD/$src" "$dest"; then
    log "unchanged: $dest"
    continue
  fi
  changed=1
  if [[ $APPLY -eq 0 ]]; then
    log "would install $dest (mode $mode):"
    diff -u "${dest}" "$BUILD/$src" 2>/dev/null || diff -u /dev/null "$BUILD/$src" || true
    continue
  fi
  if [[ "$dest" == /etc/sudoers.d/* ]]; then
    visudo -cf "$BUILD/$src" >/dev/null || die "sudoers file failed visudo -c; not installed"
  fi
  [[ -f "$dest" ]] && cp -p "$dest" "$dest.bak.$(date -u +%Y%m%d%H%M%S)"
  install -o root -g root -m "$mode" "$BUILD/$src" "$dest.tmp"
  mv -f "$dest.tmp" "$dest"
  log "installed $dest"
done

if [[ $APPLY -eq 1 && $changed -eq 1 ]]; then
  case "${QUEUE_SUPERVISOR:-systemd}" in
    systemd)
      systemctl daemon-reload
      systemctl enable "${QUEUE_SERVICE}.service"
      if [[ -f "$CURRENT_LINK/artisan" ]]; then
        systemctl restart "${QUEUE_SERVICE}.service"
        log "queue worker enabled and (re)started: systemctl status ${QUEUE_SERVICE}"
      else
        log "queue worker enabled (starts on boot); start it after the first release: systemctl start ${QUEUE_SERVICE}"
      fi ;;
    supervisor)
      supervisorctl reread && supervisorctl update
      log "queue worker registered with supervisor" ;;
  esac
fi
[[ $APPLY -eq 1 ]] || log "dry run only; re-run with --apply to install"
