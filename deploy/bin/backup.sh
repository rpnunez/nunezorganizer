#!/usr/bin/env bash
# Encrypted nightly backup (issue #21, step 4). Run as the app user (cron does this).
#
#   backup.sh [--label manual]
#
# Writes, never in plaintext:
#   <slug>-<UTC ts>[-label].sql.gz.<age|gpg>     consistent MariaDB dump (--single-transaction)
#   <slug>-<UTC ts>[-label].files.tar.gz.<..>    shared/.env + shared/storage/app
#   <slug>-<UTC ts>[-label].sha256               checksums of the two files above
# then deletes sets older than BACKUP_RETENTION_DAYS.
#
# Encryption uses PUBLIC keys only (age recipients file or a gpg recipient);
# the private key must live off the server. See docs/runbook.md#backups.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

LABEL=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --label) LABEL="${2:?}"; [[ "$LABEL" =~ ^[A-Za-z0-9_-]+$ ]] || die "label: letters, digits, _ and - only"; shift 2 ;;
    -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config
require_app_user
require_cmd gzip tar sha256sum flock

DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$DUMP_BIN" ]] || die "neither mariadb-dump nor mysqldump found"
[[ -r "$BACKUP_DB_DEFAULTS_FILE" ]] || die "DB option file not readable: $BACKUP_DB_DEFAULTS_FILE (run provision-database.sh --apply)"
[[ "$(stat -c '%a' "$BACKUP_DB_DEFAULTS_FILE")" =~ ^[46]00$ ]] || die "$BACKUP_DB_DEFAULTS_FILE must be mode 600"

case "${BACKUP_ENCRYPTION:-age}" in
  age)
    require_cmd age
    [[ -s "$BACKUP_AGE_RECIPIENTS_FILE" ]] || die "age recipients file missing/empty: $BACKUP_AGE_RECIPIENTS_FILE"
    grep -q 'AGE-SECRET-KEY' "$BACKUP_AGE_RECIPIENTS_FILE" && die "$BACKUP_AGE_RECIPIENTS_FILE contains a PRIVATE key; keep only public recipients (age1...) on the server"
    EXT="age"
    encrypt() { age -R "$BACKUP_AGE_RECIPIENTS_FILE"; }
    ;;
  gpg)
    require_cmd gpg
    [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] || die "BACKUP_GPG_RECIPIENT not set"
    EXT="gpg"
    encrypt() { gpg --batch --yes --trust-model always --encrypt --recipient "$BACKUP_GPG_RECIPIENT"; }
    ;;
  *) die "BACKUP_ENCRYPTION must be age or gpg (plaintext backups are not supported)" ;;
esac

umask 077
mkdir -p "$BACKUP_DIR"
exec 8>"$BACKUP_DIR/.backup.lock"
flock -n 8 || die "another backup is running"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$BACKUP_DIR/${APP_SLUG}-${TS}${LABEL:+-$LABEL}"
DB_OUT="$BASE.sql.gz.$EXT"
FILES_OUT="$BASE.files.tar.gz.$EXT"

cleanup() { rm -f -- "$DB_OUT.partial" "$FILES_OUT.partial"; }
trap cleanup EXIT

log "dumping $DB_NAME"
# --defaults-extra-file must come first. Credentials never appear in argv.
"$DUMP_BIN" --defaults-extra-file="$BACKUP_DB_DEFAULTS_FILE" \
  --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces \
  --default-character-set=utf8mb4 --databases "$DB_NAME" \
  | gzip -9 | encrypt > "$DB_OUT.partial"
[[ -s "$DB_OUT.partial" ]] || die "database dump is empty"
mv -- "$DB_OUT.partial" "$DB_OUT"

log "archiving shared/.env and shared/storage/app"
paths=()
[[ -e "$SHARED_DIR/.env" ]] && paths+=(.env)
[[ -d "$SHARED_DIR/storage/app" ]] && paths+=(storage/app)
if [[ ${#paths[@]} -gt 0 ]]; then
  tar -C "$SHARED_DIR" -czf - "${paths[@]}" | encrypt > "$FILES_OUT.partial"
  mv -- "$FILES_OUT.partial" "$FILES_OUT"
else
  FILES_OUT=""
fi

(cd "$BACKUP_DIR" && sha256sum -- "$(basename "$DB_OUT")" ${FILES_OUT:+"$(basename "$FILES_OUT")"} > "$BASE.sha256")
trap - EXIT
log "backup written: $(basename "$BASE").*  ($(du -ch "$DB_OUT" ${FILES_OUT:+"$FILES_OUT"} | tail -n1 | cut -f1))"

if [[ -n "${BACKUP_OFFSITE_HOOK:-}" ]]; then
  log "running offsite hook"
  "$BACKUP_OFFSITE_HOOK" "$DB_OUT" ${FILES_OUT:+"$FILES_OUT"} "$BASE.sha256" || die "offsite hook failed"
fi

days="${BACKUP_RETENTION_DAYS:-14}"
[[ "$days" =~ ^[0-9]+$ && "$days" -ge 1 ]] || die "BACKUP_RETENTION_DAYS must be a positive integer"
while IFS= read -r -d '' old; do
  log "retention: deleting $(basename "$old")"
  rm -f -- "$old"
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${APP_SLUG}-*" -mtime "+$days" -print0)

touch "$BACKUP_DIR/.last-success"
log "backup complete"
