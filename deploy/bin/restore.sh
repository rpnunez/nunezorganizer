#!/usr/bin/env bash
# Verify or restore an encrypted backup set (issue #21, steps 4 and 7: restore drill).
#
#   restore.sh --set <path/to/slug-TS[-label]> --identity <age key file> [options]
#
#   --verify-only           check checksums, decrypt and gunzip -t; restore nothing
#   --database NAME         target DB (default: ${DB_NAME}_restore_test)
#   --files-to DIR          also extract the files archive into DIR (never in place)
#   --mysql-defaults FILE   client option file with admin creds (default: socket auth as the caller)
#   --into-production --yes-overwrite-production
#                           restore into DB_NAME itself (destructive; maintenance mode first)
#
# --set is the common prefix of the backup files (without .sql.gz.age etc).
# For gpg backups, pass --identity gpg (uses your keyring).
# The private key should be copied to the server only for the duration of a
# drill, or run this on another machine that has the backup files.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

SET=""; IDENTITY=""; VERIFY_ONLY=0; TARGET_DB=""; FILES_TO=""; DEFAULTS=""; INTO_PROD=0; YES_PROD=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --set) SET="${2:?}"; shift 2 ;;
    --identity) IDENTITY="${2:?}"; shift 2 ;;
    --verify-only) VERIFY_ONLY=1; shift ;;
    --database) TARGET_DB="${2:?}"; shift 2 ;;
    --files-to) FILES_TO="${2:?}"; shift 2 ;;
    --mysql-defaults) DEFAULTS="${2:?}"; shift 2 ;;
    --into-production) INTO_PROD=1; shift ;;
    --yes-overwrite-production) YES_PROD=1; shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config
[[ -n "$SET" && -n "$IDENTITY" ]] || die "--set and --identity are required (see --help)"
SET="${SET%.sha256}"

dbf=(); filesf=()
for ext in age gpg; do
  [[ -f "$SET.sql.gz.$ext" ]] && dbf+=("$SET.sql.gz.$ext")
  [[ -f "$SET.files.tar.gz.$ext" ]] && filesf+=("$SET.files.tar.gz.$ext")
done
[[ ${#dbf[@]} -eq 1 ]] || die "expected exactly one $SET.sql.gz.{age,gpg}"
DB_FILE="${dbf[0]}"
FILES_FILE="${filesf[0]:-}"

decrypt() {
  case "$1" in
    *.age) require_cmd age; age -d -i "$IDENTITY" "$1" ;;
    *.gpg) require_cmd gpg; gpg --batch --quiet --decrypt "$1" ;;
  esac
}

log "verifying checksums"
[[ -r "$SET.sha256" ]] || die "missing $SET.sha256"
(cd "$(dirname "$SET")" && sha256sum --quiet -c "$(basename "$SET").sha256") || die "checksum mismatch"

log "test-decrypting $(basename "$DB_FILE")"
decrypt "$DB_FILE" | gzip -t || die "database archive does not decrypt/decompress"
if [[ -n "$FILES_FILE" ]]; then
  decrypt "$FILES_FILE" | tar -tzf - > /dev/null || die "files archive does not decrypt/list"
fi
if [[ $VERIFY_ONLY -eq 1 ]]; then
  log "verify OK: $(basename "$SET")"
  exit 0
fi

TARGET_DB="${TARGET_DB:-${DB_NAME}_restore_test}"
[[ "$TARGET_DB" =~ ^[A-Za-z0-9_]+$ ]] || die "database name must match [A-Za-z0-9_]+"
if [[ "$TARGET_DB" == "$DB_NAME" ]]; then
  [[ $INTO_PROD -eq 1 && $YES_PROD -eq 1 ]] || die "refusing to restore into production DB $DB_NAME without --into-production --yes-overwrite-production"
  warn "restoring INTO PRODUCTION ($DB_NAME). Put the app in maintenance mode first (artisan down)."
fi

CLIENT="$(command -v mariadb || command -v mysql || true)"
[[ -n "$CLIENT" ]] || die "mariadb/mysql client not found"
client() {
  if [[ -n "$DEFAULTS" ]]; then "$CLIENT" --defaults-extra-file="$DEFAULTS" "$@"; else "$CLIENT" "$@"; fi
}

log "restoring into $TARGET_DB"
client -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# The dump was taken with --databases, so it contains CREATE DATABASE/USE for the
# original name. Rewrite those two statements (only at line start) to the target.
decrypt "$DB_FILE" | gunzip \
  | sed -E "s/^(CREATE DATABASE( \/\*![0-9]+ IF NOT EXISTS\*\/)? )\`$DB_NAME\`/\1\`$TARGET_DB\`/; s/^USE \`$DB_NAME\`;/USE \`$TARGET_DB\`;/" \
  | client "$TARGET_DB"

log "tables restored into $TARGET_DB:"
client -NBe "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='$TARGET_DB' ORDER BY TABLE_NAME"

if [[ -n "$FILES_TO" && -n "$FILES_FILE" ]]; then
  [[ -e "$FILES_TO" && -n "$(ls -A "$FILES_TO" 2>/dev/null)" ]] && die "--files-to $FILES_TO is not empty"
  mkdir -p "$FILES_TO"; chmod 700 "$FILES_TO"
  decrypt "$FILES_FILE" | tar -xzf - -C "$FILES_TO"
  log "files extracted to $FILES_TO (copy what you need into shared/ by hand)"
fi

log "restore complete. Drop the drill DB when done: DROP DATABASE \`$TARGET_DB\`;"
