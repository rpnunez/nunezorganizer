#!/usr/bin/env bash
# Validate a production .env without printing any values (issue #21, step 5).
#
#   check-env.sh [path/to/.env]      (default: $APP_ROOT/shared/.env)
#
# Fails on: wrong permissions, APP_ENV != production, APP_DEBUG on, missing or
# placeholder APP_KEY / DB_PASSWORD, non-HTTPS APP_URL, insecure session cookies,
# debug logging, or open registration. Warnings don't fail the check.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

ENV_FILE="${1:-}"
if [[ -z "$ENV_FILE" ]]; then
  load_config
  ENV_FILE="$SHARED_DIR/.env"
fi
[[ -f "$ENV_FILE" ]] || die ".env not found: $ENV_FILE"

errors=0
fail() { printf '  FAIL %s\n' "$*" >&2; errors=$((errors + 1)); }
note() { printf '  warn %s\n' "$*" >&2; }

# get KEY — last assignment wins (like phpdotenv); strips surrounding quotes. Never printed.
get() {
  local line val
  line="$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1=" "$ENV_FILE" | tail -n1 || true)"
  val="${line#*=}"
  val="${val%%[[:space:]]#*}"
  val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
  printf '%s' "$val"
}
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

mode="$(stat -c '%a' "$ENV_FILE")"
[[ "$mode" =~ ^[46]00$ ]] || fail ".env mode is $mode; expected 600 (or 400)"
if [[ -n "${APP_USER:-}" && "$(stat -c '%U' "$ENV_FILE")" != "$APP_USER" ]]; then
  fail ".env owner is $(stat -c '%U' "$ENV_FILE"); expected $APP_USER"
fi

[[ "$(get APP_ENV)" == "production" ]] || fail "APP_ENV must be production"
[[ "$(lower "$(get APP_DEBUG)")" =~ ^(false|0|)$ ]] || fail "APP_DEBUG must be false"
key="$(get APP_KEY)"
[[ "$key" =~ ^base64:.{40,}$ ]] || fail "APP_KEY missing or not a base64: key"
[[ "$(get APP_URL)" == https://* ]] || fail "APP_URL must start with https://"

dbpass="$(get DB_PASSWORD)"
[[ -n "$dbpass" ]] || fail "DB_PASSWORD is empty (run provision-database.sh --apply)"
[[ "$dbpass" =~ ^(secret|password|root|changeme|CHANGE_ME)$ ]] && fail "DB_PASSWORD is a placeholder"
[[ "$(get DB_USERNAME)" == "root" ]] && fail "DB_USERNAME must not be root"

[[ "$(lower "$(get SESSION_SECURE_COOKIE)")" == "true" ]] || fail "SESSION_SECURE_COOKIE must be true"
[[ "$(lower "$(get SESSION_HTTP_ONLY)")" =~ ^(true|)$ ]] || fail "SESSION_HTTP_ONLY must be true (or unset)"
[[ "$(lower "$(get SESSION_ENCRYPT)")" == "true" ]] || note "SESSION_ENCRYPT is not true"
[[ "$(lower "$(get LOG_LEVEL)")" == "debug" ]] && fail "LOG_LEVEL=debug in production (use warning or error)"

reg="$(lower "$(get NUNEZ_REGISTRATION_MODE)")"
[[ "$reg" == "open" ]] && fail "NUNEZ_REGISTRATION_MODE=open; use invite (default) or approval"

[[ "$(lower "$(get MAIL_MAILER)")" =~ ^(log|array|)$ ]] && note "MAIL_MAILER is log/array/unset: password reset & verification emails won't be delivered"
[[ "$(get MAIL_HOST)" == "mailpit" ]] && fail "MAIL_HOST=mailpit is the dev value"
[[ "$(get DB_HOST)" == "mariadb" ]] && fail "DB_HOST=mariadb is the Docker dev value"
[[ -z "$(get TELEGRAM_BOT_TOKEN)" ]] && note "TELEGRAM_BOT_TOKEN empty (fine until M4 ships)"

if (( errors > 0 )); then
  die "$ENV_FILE: $errors problem(s); fix them before releasing"
fi
log "$ENV_FILE looks production-ready"
