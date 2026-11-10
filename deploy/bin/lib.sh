# shellcheck shell=bash
# Shared helpers for deploy/bin scripts. Source it; do not execute it.

set -euo pipefail
shopt -u patsub_replacement 2>/dev/null || true   # bash 5.2: keep '&' literal in ${x//a/b}

DEPLOY_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC2034  # used by the scripts that source this file
DEPLOY_DIR="$(dirname "$DEPLOY_BIN_DIR")"

log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
warn() { printf '[%s] WARN: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; exit 1; }

require_cmd() {
  local c
  for c in "$@"; do command -v "$c" >/dev/null 2>&1 || die "required command not found: $c"; done
}

# load_config — sources the deploy config and checks the variables every script needs.
load_config() {
  local cfg="${NUNEZ_DEPLOY_CONFIG:-/etc/nunezorganizer/deploy.env}"
  [[ -r "$cfg" ]] || die "deploy config not readable: $cfg (copy deploy/deploy.env.example there, or set NUNEZ_DEPLOY_CONFIG)"
  # shellcheck disable=SC1090
  source "$cfg"
  NUNEZ_DEPLOY_CONFIG="$cfg"
  local v
  for v in APP_SLUG APP_USER APP_ROOT APP_URL PHP_BIN; do
    [[ -n "${!v:-}" ]] || die "config $cfg: $v is not set"
  done
  [[ "$APP_ROOT" == /* && "$APP_ROOT" != "/" ]] || die "APP_ROOT must be an absolute path other than /"
  RELEASES_DIR="$APP_ROOT/releases"
  SHARED_DIR="$APP_ROOT/shared"
  CURRENT_LINK="$APP_ROOT/current"
  # shellcheck disable=SC2034
  REPO_MIRROR="$APP_ROOT/repo.git"
  DEPLOY_LOG_DIR="$SHARED_DIR/logs"
}

# Refuse to run as root for scripts that operate on the app tree (files must stay app-user owned).
require_app_user() {
  [[ "${NUNEZ_ALLOW_ANY_USER:-0}" == "1" ]] && return 0
  [[ "$(id -un)" == "$APP_USER" ]] || die "run this as $APP_USER (e.g. sudo -u $APP_USER $0 ...), not as $(id -un)"
}

require_root() {
  [[ $EUID -eq 0 ]] || die "run this as root"
}

# Put PHP_BIN's directory first so composer's '#!/usr/bin/env php' and artisan use PHP 8.4.
use_php_path() {
  PATH="$(dirname "$PHP_BIN"):$PATH"
  export PATH
}

artisan() {
  local dir="$1"; shift
  (cd "$dir" && "$PHP_BIN" artisan "$@")
}

# release_list — release directory names, oldest first (names start with a UTC timestamp).
release_list() {
  [[ -d "$RELEASES_DIR" ]] || return 0
  find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | LC_ALL=C sort
}

current_release() {
  [[ -L "$CURRENT_LINK" ]] || return 0
  basename "$(readlink "$CURRENT_LINK")"
}

# Atomically point CURRENT_LINK at releases/$1 (relative target, rename(2) swap).
switch_current() {
  local name="$1"
  [[ -d "$RELEASES_DIR/$name" ]] || die "release not found: $name"
  ln -sfn "releases/$name" "$APP_ROOT/.current.tmp"
  mv -Tf "$APP_ROOT/.current.tmp" "$CURRENT_LINK"
}

reload_web() {
  if [[ -z "${WEB_RELOAD_CMD:-}" ]]; then
    warn "WEB_RELOAD_CMD empty: restart OpenLiteSpeed by hand so LSPHP picks up the new release"
    return 0
  fi
  # shellcheck disable=SC2086
  if ! $WEB_RELOAD_CMD; then
    warn "web reload failed ($WEB_RELOAD_CMD): restart OpenLiteSpeed by hand"
  fi
}

# http_status URL — prints the HTTP status code (000 on connection failure).
http_status() {
  local args=(-sS -o /dev/null -w '%{http_code}' --max-time 10)
  [[ -n "${HEALTHCHECK_RESOLVE:-}" ]] && args+=(--resolve "$HEALTHCHECK_RESOLVE")
  curl "${args[@]}" "$1" 2>/dev/null || true
}

# check_health — both /healthz and /readyz must return 200.
check_health() {
  local base="${APP_URL%/}" code
  for path in healthz readyz; do
    code="$(http_status "$base/$path")"
    if [[ "$code" != "200" ]]; then
      warn "health: /$path returned ${code:-none}"
      return 1
    fi
  done
  return 0
}

# Restart queue workers after code changes: Laravel signals running workers via the cache.
restart_queue() {
  local dir="$1"
  artisan "$dir" queue:restart || warn "queue:restart failed; restart $QUEUE_SERVICE by hand"
}

record() {
  mkdir -p "$DEPLOY_LOG_DIR"
  printf '%s\t%s\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(id -un)" "$*" >> "$DEPLOY_LOG_DIR/releases.log"
}
