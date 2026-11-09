#!/usr/bin/env bash
# Cut and activate a new release (issue #21, step 3). Run as the app user.
#
#   release.sh [--ref <branch|tag|sha>] [--migrate] [--maintenance] [--no-health-rollback]
#
# Steps: lock → preflight (.env, PHP) → fetch mirror → export ref into
# releases/<UTC ts>-<sha> → link shared .env/storage → composer install --no-dev
# → npm ci && npm run build → pending-migration gate → (migrate --force only
# with --migrate) → optimize caches → atomic symlink swap → web reload +
# queue:restart → health check (auto-rollback if no migrations ran) → prune.
#
# Without --migrate the release aborts BEFORE the swap if migrations are
# pending, so production never runs code against a schema it wasn't built for.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

REF=""
DO_MIGRATE=0
MAINTENANCE=0
HEALTH_ROLLBACK=1

usage() { sed -n '2,13p' "$0"; exit "${1:-0}"; }
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) REF="${2:?--ref needs a value}"; shift 2 ;;
    --migrate) DO_MIGRATE=1; shift ;;
    --maintenance) MAINTENANCE=1; shift ;;
    --no-health-rollback) HEALTH_ROLLBACK=0; shift ;;
    -h|--help) usage 0 ;;
    *) warn "unknown option: $1"; usage 2 ;;
  esac
done

load_config
require_app_user
require_cmd git tar flock curl "$PHP_BIN"
use_php_path
REF="${REF:-${DEFAULT_REF:-master}}"
mkdir -p "$RELEASES_DIR" "$DEPLOY_LOG_DIR"

exec 9>"$APP_ROOT/.deploy.lock"
flock -n 9 || die "another release/rollback is running (lock: $APP_ROOT/.deploy.lock)"

# ---------------------------------------------------------------- preflight
log "preflight"
APP_USER="$APP_USER" "$DEPLOY_SELF_DIR/check-env.sh" "$SHARED_DIR/.env"
php_id="$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')"
(( php_id >= ${PHP_MIN_VERSION_ID:-80400} )) || die "$PHP_BIN is PHP_VERSION_ID $php_id, need >= ${PHP_MIN_VERSION_ID:-80400}"
missing=""
mods="$("$PHP_BIN" -m | tr '[:upper:]' '[:lower:]')"
for ext in bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_mysql tokenizer xml; do
  grep -qx "$ext" <<<"$mods" || missing+="$ext "
done
[[ -z "$missing" ]] || die "PHP extensions missing from $PHP_BIN: $missing"
[[ -d "$SHARED_DIR/storage" ]] || die "$SHARED_DIR/storage missing; run bootstrap-layout.sh first"
command -v "${NPM_BIN:-npm}" >/dev/null 2>&1 || die "npm not found: install Node.js ${NODE_MIN_VERSION:-22} (docs/runbook.md#prerequisites)"
node_v="$(node -p 'process.versions.node' 2>/dev/null || echo 0.0.0)"
if [[ "$(printf '%s\n%s\n' "${NODE_MIN_VERSION:-22.18.0}" "$node_v" | sort -V | head -n1)" != "${NODE_MIN_VERSION:-22.18.0}" ]]; then
  die "Node.js $node_v is too old; the asset build needs >= ${NODE_MIN_VERSION:-22.18.0}"
fi

# ---------------------------------------------------------------- fetch & export
if [[ -d "$REPO_MIRROR" ]]; then
  log "fetching $REPO_URL"
  git --git-dir="$REPO_MIRROR" remote set-url origin "$REPO_URL"
  git --git-dir="$REPO_MIRROR" fetch --prune --tags origin '+refs/heads/*:refs/heads/*'
else
  log "cloning mirror of $REPO_URL"
  git clone --quiet --mirror "$REPO_URL" "$REPO_MIRROR"
fi
SHA="$(git --git-dir="$REPO_MIRROR" rev-parse --verify --quiet "${REF}^{commit}")" || die "ref not found: $REF"
NAME="$(date -u +%Y%m%d%H%M%S)-${SHA:0:7}"
REL="$RELEASES_DIR/$NAME"
PREV="$(current_release)"
[[ ! -e "$REL" ]] || die "release dir already exists: $REL"

cleanup_failed() {
  local rc=$?
  if [[ $rc -ne 0 && -n "${REL:-}" && -d "$REL" && "$(current_release)" != "$NAME" ]]; then
    warn "release failed (exit $rc); removing $REL"
    rm -rf -- "$REL"
    record "FAILED $NAME ref=$REF"
  fi
  if [[ $rc -ne 0 && "${WENT_DOWN:-0}" == "1" && -n "$PREV" ]]; then
    warn "bringing the app back up (it was put in maintenance mode)"
    artisan "$RELEASES_DIR/$PREV" up || true
  fi
}
trap cleanup_failed EXIT

log "exporting $REF ($SHA) -> releases/$NAME"
mkdir -p "$REL"
git --git-dir="$REPO_MIRROR" archive --format=tar "$SHA" | tar -x -C "$REL"
printf '%s\n' "$SHA" > "$REL/REVISION"
[[ -f "$REL/artisan" ]] || die "ref $REF does not contain a Laravel app (no artisan)"

# ---------------------------------------------------------------- shared files
rm -rf -- "$REL/storage"
ln -s "$SHARED_DIR/storage" "$REL/storage"
ln -sfn "$SHARED_DIR/.env" "$REL/.env"
mkdir -p "$REL/bootstrap/cache"

# ---------------------------------------------------------------- dependencies
log "composer install --no-dev"
(cd "$REL" && COMPOSER_NO_INTERACTION=1 "${COMPOSER_BIN:-composer}" install --no-dev --prefer-dist --optimize-autoloader --no-progress)

log "npm ci && npm run build"
(cd "$REL" && "${NPM_BIN:-npm}" ci --no-audit --no-fund && "${NPM_BIN:-npm}" run build)
rm -rf -- "$REL/node_modules"
[[ -d "$REL/public/build" ]] || die "asset build produced no public/build"

# ---------------------------------------------------------------- migrations gate
log "checking for pending migrations"
pretend="$(artisan "$REL" migrate --pretend --force --no-interaction 2>&1)" || die "migrate --pretend failed:"$'\n'"$pretend"
MIGRATED=0
if grep -qi 'nothing to migrate' <<<"$pretend"; then
  log "no pending migrations"
else
  printf '%s\n' "$pretend" > "$DEPLOY_LOG_DIR/pending-migrations-$NAME.sql.txt"
  if [[ $DO_MIGRATE -ne 1 ]]; then
    die "pending migrations (SQL preview: $DEPLOY_LOG_DIR/pending-migrations-$NAME.sql.txt). Review, back up (backup.sh), then re-run with --migrate"
  fi
  if [[ $MAINTENANCE -eq 1 && -n "$PREV" ]]; then
    log "maintenance mode on"
    artisan "$RELEASES_DIR/$PREV" down --retry=15 && WENT_DOWN=1
  fi
  log "running migrations"
  artisan "$REL" migrate --force --no-interaction
  MIGRATED=1
fi

# ---------------------------------------------------------------- caches & swap
log "building caches"
artisan "$REL" optimize

log "activating releases/$NAME (previous: ${PREV:-none})"
switch_current "$NAME"
record "ACTIVATED $NAME ref=$REF sha=$SHA prev=${PREV:-none} migrated=$MIGRATED"
trap - EXIT

if [[ "${WENT_DOWN:-0}" == "1" ]]; then
  artisan "$CURRENT_LINK" up || warn "artisan up failed; run it by hand"
fi
reload_web
restart_queue "$CURRENT_LINK"

# ---------------------------------------------------------------- verify
sleep "${HEALTHCHECK_DELAY_SECONDS:-3}"
if check_health; then
  log "health OK"
else
  if [[ $HEALTH_ROLLBACK -eq 1 && $MIGRATED -eq 0 && -n "$PREV" ]]; then
    warn "health check failed: rolling back to $PREV"
    switch_current "$PREV"
    record "AUTO-ROLLBACK $NAME -> $PREV (health check failed)"
    reload_web
    restart_queue "$CURRENT_LINK"
    die "release $NAME failed its health check and was rolled back to $PREV"
  fi
  [[ $MIGRATED -eq 1 ]] && warn "migrations ran, so no automatic rollback. See docs/runbook.md#rollback"
  die "release $NAME is live but failing its health check"
fi

# ---------------------------------------------------------------- prune
KEEP="${KEEP_RELEASES:-5}"
mapfile -t all < <(release_list)
excess=$(( ${#all[@]} - KEEP ))
for (( i = 0; i < excess; i++ )); do
  old="${all[$i]}"
  [[ "$old" == "$NAME" || "$old" == "$PREV" ]] && continue
  log "pruning releases/$old"
  rm -rf -- "${RELEASES_DIR:?}/$old"
done

log "release $NAME is live"
