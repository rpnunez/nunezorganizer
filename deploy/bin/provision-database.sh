#!/usr/bin/env bash
# Create the app database and least-privilege users (issue #21, step 4). Run as root.
#
#   provision-database.sh            # dry run: print the SQL (passwords masked)
#   provision-database.sh --apply    # create DB/users if missing, write credentials
#   provision-database.sh --apply --rotate   # also reset both passwords
#
# Creates:
#   DB_NAME        utf8mb4 database
#   DB_USER        app user: DML + DDL on DB_NAME only (migrations need DDL); no global privileges
#   DB_BACKUP_USER read-only dump user: SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT on DB_NAME
# Both exist @localhost and @127.0.0.1 only. Nothing is granted on any other schema,
# so the WordPress database stays out of reach.
#
# Passwords are generated here, sent to the server over stdin (never argv), and
# written only to APP_ROOT/shared/.env (DB_PASSWORD) and BACKUP_DB_DEFAULTS_FILE,
# both 0600 and owned by APP_USER. They are never printed.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

APPLY=0; ROTATE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --rotate) ROTATE=1; shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config
require_cmd openssl
for v in DB_NAME DB_USER DB_BACKUP_USER; do
  [[ "${!v}" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "$v must match [A-Za-z0-9_]"
done
ENV_FILE="$SHARED_DIR/.env"
CLIENT="${DB_ADMIN_CLIENT:-mariadb}"

gen() { openssl rand -hex 24; }

sql_for() {   # sql_for APP_PASS BACKUP_PASS
  local app="$1" bk="$2" h
  printf "CREATE DATABASE IF NOT EXISTS \`%s\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" "$DB_NAME"
  for h in localhost 127.0.0.1; do
    printf "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_USER" "$h" "$app"
    printf "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_BACKUP_USER" "$h" "$bk"
    if [[ $ROTATE -eq 1 ]]; then
      printf "ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_USER" "$h" "$app"
      printf "ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_BACKUP_USER" "$h" "$bk"
    fi
    printf "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, CREATE VIEW, SHOW VIEW, TRIGGER ON \`%s\`.* TO '%s'@'%s';\n" "$DB_NAME" "$DB_USER" "$h"
    printf "GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON \`%s\`.* TO '%s'@'%s';\n" "$DB_NAME" "$DB_BACKUP_USER" "$h"
  done
}

if [[ $APPLY -eq 0 ]]; then
  log "dry run (nothing executed). SQL that --apply would send:"
  sql_for '********' '********'
  exit 0
fi

require_root
require_cmd "$CLIENT"
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE missing: run bootstrap-layout.sh as $APP_USER first"

exists="$("$CLIENT" -NBe "SELECT COUNT(*) FROM mysql.user WHERE user IN ('$DB_USER','$DB_BACKUP_USER')" </dev/null)"
if [[ "$exists" -gt 0 && $ROTATE -eq 0 ]]; then
  log "users already exist: ensuring database and grants only (use --rotate to reset passwords)"
  sql_for 'unused-because-users-exist' 'unused-because-users-exist' | grep -v '^CREATE USER' | "$CLIENT"
  log "done (credentials unchanged)"
  exit 0
fi

APP_PASS="$(gen)"; BK_PASS="$(gen)"
sql_for "$APP_PASS" "$BK_PASS" | "$CLIENT"
log "database $DB_NAME and users $DB_USER / $DB_BACKUP_USER ready"

# Write DB_PASSWORD into shared/.env (replace or append), keeping owner and mode.
tmp="$(mktemp "$SHARED_DIR/.env.XXXXXX")"
if grep -qE '^DB_PASSWORD=' "$ENV_FILE"; then
  sed -E "s|^DB_PASSWORD=.*$|DB_PASSWORD=$APP_PASS|" "$ENV_FILE" > "$tmp"
else
  { cat "$ENV_FILE"; printf 'DB_PASSWORD=%s\n' "$APP_PASS"; } > "$tmp"
fi
chown "$APP_USER:$APP_GROUP" "$tmp"; chmod 600 "$tmp"
mv -f "$tmp" "$ENV_FILE"
log "DB_PASSWORD written to $ENV_FILE"

install -d -m 700 -o "$APP_USER" -g "$APP_GROUP" "$(dirname "$BACKUP_DB_DEFAULTS_FILE")"
(
  umask 077
  printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_BACKUP_USER" "$BK_PASS" "$DB_HOST" "$DB_PORT" > "$BACKUP_DB_DEFAULTS_FILE"
)
chown "$APP_USER:$APP_GROUP" "$BACKUP_DB_DEFAULTS_FILE"; chmod 600 "$BACKUP_DB_DEFAULTS_FILE"
log "backup credentials written to $BACKUP_DB_DEFAULTS_FILE"
if [[ -L "$CURRENT_LINK" ]]; then
  log "a release is live: run 'php artisan config:cache' in $CURRENT_LINK as $APP_USER (or re-release) to pick up the new password"
fi
