#!/usr/bin/env bash
# Validate a production .env without printing any values (issue #21, step 5).
#
#   check-env.sh [path/to/.env]      (default: $APP_ROOT/shared/.env)
#
# File checks (mode, owner) are done here; the content rules (APP_ENV, APP_DEBUG,
# APP_KEY, https APP_URL, secure cookies, DB credentials, log level, registration
# mode, mail) live in one place, setup.php --validate-env, which this calls.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

ENV_FILE="${1:-}"
if [[ -z "$ENV_FILE" ]]; then
  load_config
  ENV_FILE="$SHARED_DIR/.env"
fi
[[ -f "$ENV_FILE" ]] || die ".env not found: $ENV_FILE"

SETUP_PHP="$DEPLOY_DIR/../setup.php"
[[ -f "$SETUP_PHP" ]] || die "setup.php not found next to deploy/ ($SETUP_PHP)"

errors=0
fail() { printf '  FAIL %s\n' "$*" >&2; errors=$((errors + 1)); }

mode="$(stat -c '%a' "$ENV_FILE")"
[[ "$mode" =~ ^[46]00$ ]] || fail ".env mode is $mode; expected 600 (or 400)"
if [[ -n "${APP_USER:-}" && "$(stat -c '%U' "$ENV_FILE")" != "$APP_USER" ]]; then
  fail ".env owner is $(stat -c '%U' "$ENV_FILE"); expected $APP_USER"
fi

"${PHP_BIN:-php}" "$SETUP_PHP" --validate-env="$ENV_FILE" >&2 || errors=$((errors + 1))

if (( errors > 0 )); then
  die "$ENV_FILE is not production-ready; fix the problems above before releasing"
fi
log "$ENV_FILE looks production-ready"
