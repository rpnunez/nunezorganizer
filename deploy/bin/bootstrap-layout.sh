#!/usr/bin/env bash
# Create the app directory layout and a production .env skeleton (idempotent).
# Run once as the app user, after deploy.env is in place:
#
#   sudo -u nunezorg deploy/bin/bootstrap-layout.sh
#
# Never overwrites an existing shared/.env. Generates APP_KEY with openssl,
# so it works before PHP/composer are set up.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

load_config
require_app_user
require_cmd openssl
umask 027

log "creating layout under $APP_ROOT"
mkdir -p "$RELEASES_DIR" "$DEPLOY_LOG_DIR" \
  "$SHARED_DIR/storage/app/public" "$SHARED_DIR/storage/app/private" \
  "$SHARED_DIR/storage/framework/cache/data" "$SHARED_DIR/storage/framework/sessions" \
  "$SHARED_DIR/storage/framework/views" "$SHARED_DIR/storage/logs"
# APP_ROOT and releases/ 711: the web server (not APP_USER) must traverse them to serve current/public's
# static files, but can't list it. shared/ holds .env and storage: owner + group only.
chmod 711 "$APP_ROOT" "$RELEASES_DIR"
chmod 750 "$SHARED_DIR"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

if [[ -e "$SHARED_DIR/.env" ]]; then
  log "$SHARED_DIR/.env exists; leaving it alone"
else
  tpl="$DEPLOY_DIR/templates/env/production.env.tpl"
  [[ -r "$tpl" ]] || die "missing $tpl"
  log "writing $SHARED_DIR/.env from template (mode 600)"
  key="base64:$(openssl rand -base64 32)"
  (
    umask 077
    APP_KEY_VALUE="$key" "$DEPLOY_BIN_DIR/render-templates.sh" --stdout "$tpl" > "$SHARED_DIR/.env.tmp"
    mv "$SHARED_DIR/.env.tmp" "$SHARED_DIR/.env"
  )
  log "next: run provision-database.sh as root to set DB_PASSWORD, then fill MAIL_* in $SHARED_DIR/.env"
fi

log "layout ready"
