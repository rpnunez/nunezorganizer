#!/usr/bin/env bash
# Offline tests for deploy/ (issue #21). No server, PHP app, MariaDB or network needed:
# php/composer/npm/curl/mariadb-dump/mariadb/systemctl are replaced by stubs.
#
#   bash deploy/tests/run.sh
#
# Needs: bash, git, shellcheck (optional, skipped if missing), age (optional).

set -uo pipefail
ORIG_PATH="$PATH"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY="$(dirname "$HERE")"
BIN="$DEPLOY/bin"

pass=0; fail=0
ok()   { printf '  ok   %s\n' "$1"; pass=$((pass + 1)); }
bad()  { printf '  FAIL %s\n' "$1"; fail=$((fail + 1)); }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; fi; }
check_not() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d"; else ok "$d"; fi; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
export NUNEZ_ALLOW_ANY_USER=1 HEALTHCHECK_DELAY_SECONDS=0 GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t

# ------------------------------------------------------------------ stubs
REAL_PHP="$(command -v php || true)"
STUBS="$T/stubs"; mkdir -p "$STUBS"
CALLS="$T/calls.log"; : > "$CALLS"
export CALLS REAL_PHP FAKE_PENDING=0 FAKE_HEALTH=200 FAKE_PHP_ID=80406

cat > "$STUBS/php" <<'EOF'
#!/usr/bin/env bash
# setup.php (used by check-env.sh) runs on the real PHP.
if [[ "$1" == *setup.php ]]; then exec "$REAL_PHP" "$@"; fi
if [[ "$1" == "-r" ]]; then echo "$FAKE_PHP_ID"; exit 0; fi
if [[ "$1" == "-m" ]]; then printf '%s\n' bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_mysql tokenizer xml "Zend OPcache"; exit 0; fi
if [[ "$1" == "artisan" ]]; then
  shift
  echo "artisan $* @ $(basename "$(pwd -P)")" >> "$CALLS"
  case "$1" in
    optimize) [[ -n "${FAKE_OPTIMIZE_FAIL:-}" ]] && exit 1 ;;
    migrate)
      if [[ " $* " == *" --pretend "* ]]; then
        if [[ "$FAKE_PENDING" == "1" ]]; then echo "2026_01_01_000000_create_tasks ... create table tasks"; else echo "INFO  Nothing to migrate."; fi
      fi ;;
  esac
  exit 0
fi
exit 0
EOF
cat > "$STUBS/composer" <<'EOF'
#!/usr/bin/env bash
echo "composer $*" >> "$CALLS"
[[ "$1" == install ]] && { mkdir -p vendor; echo '<?php' > vendor/autoload.php; }
exit 0
EOF
cat > "$STUBS/npm" <<'EOF'
#!/usr/bin/env bash
echo "npm $*" >> "$CALLS"
case "$1" in ci) mkdir -p node_modules ;; run) mkdir -p public/build; echo '{}' > public/build/manifest.json ;; esac
EOF
cat > "$STUBS/node" <<'EOF'
#!/usr/bin/env bash
echo "${FAKE_NODE:-22.18.1}"
EOF
cat > "$STUBS/curl" <<'EOF'
#!/usr/bin/env bash
printf '%s' "$FAKE_HEALTH"
EOF
cat > "$STUBS/sudo" <<'EOF'
#!/usr/bin/env bash
echo "sudo $*" >> "$CALLS"
EOF
cat > "$STUBS/systemctl" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == "is-active" ]] && { echo "${FAKE_QUEUE_STATE:-active}"; [[ "${FAKE_QUEUE_STATE:-active}" == active ]]; exit; }
EOF
cat > "$STUBS/mariadb-dump" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == --defaults-extra-file=* ]] || { echo "defaults file must be first" >&2; exit 2; }
for a in "$@"; do [[ "$a" == *password* && "$a" != --defaults-extra-file=* ]] && { echo "password in argv" >&2; exit 3; }; done
[[ -n "${FAKE_DUMP_FAIL:-}" ]] && exit 1
cat <<SQL
-- MariaDB dump
CREATE DATABASE /*!32312 IF NOT EXISTS*/ \`nunezorganizer\` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE \`nunezorganizer\`;
CREATE TABLE users (id int);
INSERT INTO users VALUES (1),(2);
-- Dump completed
SQL
EOF
cat > "$STUBS/mariadb" <<'EOF'
#!/usr/bin/env bash
echo "mariadb $*" >> "$CALLS"
if [[ " $* " == *" -e "* || " $* " == *" -NBe "* ]]; then exit 0; fi
cat > "${FAKE_RESTORE_SINK:-/dev/null}"
EOF
chmod +x "$STUBS"/*
export PATH="$STUBS:$PATH"

# ------------------------------------------------------------------ fixtures
APP_ROOT="$T/srv/app"
mkdir -p "$T/etc" "$APP_ROOT"
CFG="$T/etc/deploy.env"
sed -e "s#^APP_ROOT=.*#APP_ROOT=\"$APP_ROOT\"#" \
    -e "s#^REPO_URL=.*#REPO_URL=\"$T/origin\"#" \
    -e "s#^PHP_BIN=.*#PHP_BIN=\"$STUBS/php\"#" \
    -e "s#^COMPOSER_BIN=.*#COMPOSER_BIN=\"$STUBS/composer\"#" \
    -e "s#^BACKUP_DIR=.*#BACKUP_DIR=\"$T/backups\"#" \
    -e "s#^BACKUP_AGE_RECIPIENTS_FILE=.*#BACKUP_AGE_RECIPIENTS_FILE=\"$T/etc/recipients.txt\"#" \
    -e "s#^BACKUP_DB_DEFAULTS_FILE=.*#BACKUP_DB_DEFAULTS_FILE=\"$T/etc/backup.my.cnf\"#" \
    -e "s#^KEEP_RELEASES=.*#KEEP_RELEASES=3#" \
    -e "s#^BACKUP_ENCRYPTION=.*#BACKUP_ENCRYPTION=age#" \
    -e "s#^BACKUP_GPG_HOME=.*#BACKUP_GPG_HOME=\"$T/server-gnupg\"#" \
    -e "s#^APP_USER=.*#APP_USER=\"$(id -un)\"#" \
    -e "s#^APP_GROUP=.*#APP_GROUP=\"$(id -gn)\"#" \
    "$DEPLOY/deploy.env.example" > "$CFG"
export NUNEZ_DEPLOY_CONFIG="$CFG"

# fake app repo
git init -q -b master "$T/origin"
( cd "$T/origin" && echo '<?php' > artisan && echo '{}' > composer.json && echo '{}' > package.json \
  && mkdir -p storage/logs public bootstrap/cache && touch storage/logs/.gitkeep && git add -A && git commit -qm v1 )
commit() { ( cd "$T/origin" && echo "$1" > VERSION && git add -A && git commit -qm "$1" ); }

echo "== lint"
if command -v shellcheck >/dev/null; then
  check "shellcheck (warning level)" shellcheck -x -S warning "$BIN"/*.sh "$DEPLOY"/audit/*.sh "$HERE"/run.sh
else
  echo "  skip shellcheck not installed"
fi
for f in "$BIN"/*.sh "$DEPLOY"/audit/*.sh; do check "bash -n $(basename "$f")" bash -n "$f"; done

echo "== templates"
check "render all templates" "$BIN/render-templates.sh" --out "$T/build"
check_not "no placeholders left" grep -rq '{{' "$T/build"
check "systemd unit rendered" grep -q "^ExecStart=$STUBS/php $APP_ROOT/current/artisan queue:work database --queue=notifications,default" "$T/build/systemd/nunezorganizer-queue.service"
check "vhost docroot is current/public" grep -q "^docRoot *$APP_ROOT/current/public/" "$T/build/openlitespeed/vhconf.conf"
check "nginx root is current/public" grep -q "root $APP_ROOT/current/public;" "$T/build/nginx/nunezorganizer.conf"
check "nginx uses realpath_root" grep -q 'SCRIPT_FILENAME \$realpath_root' "$T/build/nginx/nunezorganizer.conf"
check "apache docroot is current/public" grep -q "DocumentRoot $APP_ROOT/current/public" "$T/build/apache/nunezorganizer.conf"
check "php-fpm pool runs as app user" grep -qx "user = $(id -un)" "$T/build/php-fpm/nunezorganizer.conf"
check "sudoers allows only the reload command" grep -qx "$(id -un) ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm" "$T/build/sudoers/nunezorganizer"
check "cron runs schedule:run every minute" grep -q "^\* \* \* \* \* $(id -un) cd $APP_ROOT/current && $STUBS/php artisan schedule:run" "$T/build/cron/nunezorganizer.cron"
if command -v systemd-analyze >/dev/null; then
  out="$(systemd-analyze verify "$T/build/systemd/nunezorganizer-queue.service" 2>&1 || true)"
  if grep -qiE 'unknown (key|section)|invalid|failed to parse' <<<"$out"; then bad "systemd unit syntax: $out"; else ok "systemd unit syntax"; fi
fi
if command -v visudo >/dev/null; then check "sudoers syntax" visudo -cf "$T/build/sudoers/nunezorganizer"; fi
echo 'BAD={{NOT_SET_ANYWHERE}}' > "$T/bad.tpl"
check_not "render fails on unset placeholder" "$BIN/render-templates.sh" --stdout "$T/bad.tpl"
echo 'X={{APP_SLUG}} &amp;' > "$T/amp.tpl"
check "render keeps '&' literal" bash -c "'$BIN/render-templates.sh' --stdout '$T/amp.tpl' | grep -qx 'X=nunezorganizer &amp;'"

echo "== bootstrap + check-env"
check "bootstrap-layout" "$BIN/bootstrap-layout.sh"
ENVF="$APP_ROOT/shared/.env"
check "shared/.env is 600" test "$(stat -c %a "$ENVF")" = 600
check "APP_ROOT traversable by web server (711)" test "$(stat -c %a "$APP_ROOT")" = 711
check "shared/ closed to others (750)" test "$(stat -c %a "$APP_ROOT/shared")" = 750
check "APP_KEY generated" grep -qE '^APP_KEY=base64:.{40,}$' "$ENVF"
check_not "check-env rejects empty DB_PASSWORD" "$BIN/check-env.sh" "$ENVF"
sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=0123456789abcdef0123456789abcdef/' "$ENVF"
check "check-env accepts filled .env" "$BIN/check-env.sh" "$ENVF"
check "bootstrap is idempotent (keeps .env)" bash -c "'$BIN/bootstrap-layout.sh' && grep -q 0123456789abcdef '$ENVF'"
for bad_line in 'APP_DEBUG=true' 'APP_ENV=local' 'APP_URL=http://x' 'SESSION_SECURE_COOKIE=false' 'LOG_LEVEL=debug' 'NUNEZ_REGISTRATION_MODE=open' 'DB_PASSWORD=secret' 'DB_HOST=mariadb'; do
  cp -p "$ENVF" "$T/env.bad"; echo "$bad_line" >> "$T/env.bad"
  check_not "check-env rejects $bad_line" "$BIN/check-env.sh" "$T/env.bad"
done
chmod 644 "$T/env.bad"; sed -i '$d' "$T/env.bad"
check_not "check-env rejects mode 644" "$BIN/check-env.sh" "$T/env.bad"
if "$BIN/check-env.sh" "$ENVF" 2>&1 | grep -q 0123456789abcdef; then bad "check-env never prints values"; else ok "check-env never prints values"; fi

echo "== release"
current() { basename "$(readlink "$APP_ROOT/current")"; }
check "first release (umask 077)" bash -c "umask 077; '$BIN/release.sh'"
R1="$(current)"
check "current -> releases/<ts>-<sha>" test -f "$APP_ROOT/current/artisan"
check "symlink target is relative" bash -c "[[ \$(readlink '$APP_ROOT/current') == releases/* ]]"
check "storage is shared" test "$(readlink "$APP_ROOT/current/storage")" = "$APP_ROOT/shared/storage"
check ".env is shared" test "$(readlink "$APP_ROOT/current/.env")" = "$APP_ROOT/shared/.env"
check "node_modules removed" test ! -e "$APP_ROOT/current/node_modules"
check "assets built" test -f "$APP_ROOT/current/public/build/manifest.json"
check "REVISION written" test -s "$APP_ROOT/current/REVISION"
check "releases/ traversable by web server (711)" test "$(stat -c %a "$APP_ROOT/releases")" = 711
check "release dir traversable by web server (711)" test "$(stat -c %a "$APP_ROOT/releases/$R1")" = 711
check "public assets readable by others" bash -c "[[ \$(stat -c %a '$APP_ROOT/current/public/build/manifest.json') =~ [4-7]\$ ]]"
check "composer --no-dev" grep -q 'composer install --no-dev' "$CALLS"
check "optimize ran" grep -q "artisan optimize @ $R1" "$CALLS"
check "queue:restart ran" grep -q 'artisan queue:restart' "$CALLS"
check "web reload ran" grep -q 'sudo -n /usr/bin/systemctl reload php8.4-fpm' "$CALLS"
check "activation logged" grep -q "ACTIVATED $R1 .*migrated=0" "$APP_ROOT/shared/logs/releases.log"

sleep 1; commit v2; FAKE_PENDING=1
check_not "pending migrations block release without --migrate" "$BIN/release.sh"
check "current unchanged after blocked release" test "$(current)" = "$R1"
check "SQL preview saved" bash -c "ls '$APP_ROOT'/shared/logs/pending-migrations-*.sql.txt"
check "failed release dir cleaned up" test "$(find "$APP_ROOT/releases" -mindepth 1 -maxdepth 1 | wc -l)" -eq 1
: > "$CALLS"
check "release with --migrate --maintenance" "$BIN/release.sh" --migrate --maintenance
R2="$(current)"
check "migrate --force ran in new release" grep -q "artisan migrate --force --no-interaction @ $R2" "$CALLS"
check "down ran on old release" grep -q "artisan down --retry=15 @ $R1" "$CALLS"
check "up ran after swap" grep -q "artisan up @ $R2" "$CALLS"
check "migrated=1 logged" grep -q "ACTIVATED $R2 .*migrated=1" "$APP_ROOT/shared/logs/releases.log"
FAKE_PENDING=0

sleep 1; commit v3
FAKE_HEALTH=500
check_not "unhealthy release fails" "$BIN/release.sh"
check "auto-rollback to previous" test "$(current)" = "$R2"
check "auto-rollback logged" grep -q "AUTO-ROLLBACK .* -> $R2" "$APP_ROOT/shared/logs/releases.log"
FAKE_HEALTH=200

sleep 1; commit v3b; FAKE_PENDING=1; export FAKE_OPTIMIZE_FAIL=1
check_not "failure after migrations fails the release" "$BIN/release.sh" --migrate
FAILED_REL="$(grep -o 'FAILED-AFTER-MIGRATIONS [^ ]*' "$APP_ROOT/shared/logs/releases.log" | tail -n1 | cut -d' ' -f2)"
check "failed-after-migrations release is kept" test -f "$APP_ROOT/releases/$FAILED_REL/.failed-release"
check "current unchanged after post-migration failure" test "$(current)" = "$R2"
check_not "rollback --to a failed release refused" "$BIN/rollback.sh" --to "$FAILED_REL"
FAKE_PENDING=0; unset FAKE_OPTIMIZE_FAIL

check_not "unknown ref rejected" "$BIN/release.sh" --ref does-not-exist
FAKE_PHP_ID=80300 check_not "PHP < 8.4 rejected" "$BIN/release.sh"
FAKE_NODE=20.11.0 check_not "Node < 22.18 rejected" "$BIN/release.sh"

exec 9>"$APP_ROOT/.deploy.lock"; flock -n 9
check_not "concurrent release blocked by lock" "$BIN/release.sh"
exec 9>&-

for v in v4 v5 v6; do sleep 1; commit "$v"; "$BIN/release.sh" >/dev/null 2>&1 || bad "release $v"; done
check "pruned to KEEP_RELEASES=3" test "$(find "$APP_ROOT/releases" -mindepth 1 -maxdepth 1 -type d | wc -l)" -eq 3

echo "== rollback"
BEFORE="$(current)"
check "rollback --list marks current" bash -c "'$BIN/rollback.sh' --list | grep -q '^\* $BEFORE'"
check "rollback to previous" "$BIN/rollback.sh"
AFTER="$(current)"
check "current moved back" test "$AFTER" != "$BEFORE"
check "rollback --to newest" "$BIN/rollback.sh" --to "$BEFORE"
check "current restored" test "$(current)" = "$BEFORE"
check_not "rollback to missing release fails" "$BIN/rollback.sh" --to nope

echo "== backup / restore"
if command -v age >/dev/null && command -v age-keygen >/dev/null; then
  age-keygen -o "$T/identity.txt" 2>/dev/null
  age-keygen -y "$T/identity.txt" > "$T/etc/recipients.txt"
  printf '[client]\nuser=bk\npassword=x\n' > "$T/etc/backup.my.cnf"; chmod 600 "$T/etc/backup.my.cnf"
  mkdir -p "$APP_ROOT/shared/storage/app/private"; echo hello > "$APP_ROOT/shared/storage/app/private/file.txt"
  check "backup" "$BIN/backup.sh" --label test
  SET="$(find "$T/backups" -name '*.sha256' | head -n1)"; SET="${SET%.sha256}"
  check "db dump encrypted (age header)" bash -c "head -c 30 '$SET.sql.gz.age' | grep -q 'age-encryption.org'"
  check_not "no plaintext SQL on disk" grep -rqa 'CREATE TABLE' "$T/backups"
  check "backup files are 600" test "$(stat -c %a "$SET.sql.gz.age")" = 600
  check "success marker" test -f "$T/backups/.last-success"
  check "restore --verify-only" "$BIN/restore.sh" --set "$SET" --identity "$T/identity.txt" --verify-only
  FAKE_RESTORE_SINK="$T/restored.sql" check "restore into drill DB" "$BIN/restore.sh" --set "$SET" --identity "$T/identity.txt" --files-to "$T/restored-files"
  check "restore rewrote CREATE DATABASE" grep -q 'CREATE DATABASE /\*!32312 IF NOT EXISTS\*/ `nunezorganizer_restore_test`' "$T/restored.sql"
  check "restore rewrote USE" grep -qx 'USE `nunezorganizer_restore_test`;' "$T/restored.sql"
  check "files restored" grep -qx hello "$T/restored-files/storage/app/private/file.txt"
  check_not "restore into production needs explicit flags" "$BIN/restore.sh" --set "$SET" --identity "$T/identity.txt" --database nunezorganizer
  cp "$SET.sql.gz.age" "$T/keep"; echo x >> "$SET.sql.gz.age"
  check_not "tampered backup fails checksum" "$BIN/restore.sh" --set "$SET" --identity "$T/identity.txt" --verify-only
  cp "$T/keep" "$SET.sql.gz.age"
  FAKE_DUMP_FAIL=1 check_not "failing dump fails backup" "$BIN/backup.sh"
  check "no .partial files left" test -z "$(find "$T/backups" -name '*.partial')"
  cat "$T/identity.txt" >> "$T/etc/recipients.txt"
  check_not "private key in recipients file refused" "$BIN/backup.sh"
  age-keygen -y "$T/identity.txt" > "$T/etc/recipients.txt"
  touch -d '30 days ago' "$SET.sql.gz.age"
  "$BIN/backup.sh" >/dev/null 2>&1
  check "retention deletes old sets" test ! -e "$SET.sql.gz.age"
else
  echo "  skip age not installed"
fi

echo "== backup / restore (gpg)"
if command -v gpg >/dev/null; then
  ( umask 077; mkdir -p "$T/owner-gnupg" "$T/server-gnupg" )
  GNUPGHOME="$T/owner-gnupg" gpg --batch --quiet --passphrase '' --quick-gen-key 'Nunez Backup Test <backup@test.invalid>' default default never 2>/dev/null
  FPR="$(GNUPGHOME="$T/owner-gnupg" gpg --batch --with-colons --list-keys | awk -F: '/^fpr/ {print $10; exit}')"
  GNUPGHOME="$T/owner-gnupg" gpg --batch --export "$FPR" | GNUPGHOME="$T/server-gnupg" gpg --batch --quiet --import 2>/dev/null
  sed -i -e "s#^BACKUP_ENCRYPTION=.*#BACKUP_ENCRYPTION=gpg#" -e "s#^BACKUP_GPG_RECIPIENT=.*#BACKUP_GPG_RECIPIENT=\"$FPR\"#" "$CFG"
  printf '[client]\nuser=bk\npassword=x\n' > "$T/etc/backup.my.cnf"; chmod 600 "$T/etc/backup.my.cnf"
  check "gpg backup (public key only)" "$BIN/backup.sh" --label gpgtest
  GSET="$(find "$T/backups" -name '*gpgtest.sha256' | head -n1)"; GSET="${GSET%.sha256}"
  check "gpg backup file exists" test -s "$GSET.sql.gz.gpg"
  check_not "no plaintext SQL in gpg backup" grep -qa 'CREATE TABLE' "$GSET.sql.gz.gpg"
  GNUPGHOME="$T/owner-gnupg" FAKE_RESTORE_SINK="$T/restored-gpg.sql" check "gpg restore with owner key" "$BIN/restore.sh" --set "$GSET" --identity gpg
  check "gpg restore content" grep -q 'INSERT INTO users' "$T/restored-gpg.sql"
  GNUPGHOME="$T/owner-gnupg" gpg --batch --export-secret-keys "$FPR" | GNUPGHOME="$T/server-gnupg" gpg --batch --quiet --import 2>/dev/null
  check_not "gpg private key on server refused" "$BIN/backup.sh"
  sed -i "s#^BACKUP_GPG_RECIPIENT=.*#BACKUP_GPG_RECIPIENT=\"DEADBEEF\"#" "$CFG"
  check_not "missing gpg public key refused" "$BIN/backup.sh"
else
  echo "  skip gpg not installed"
fi

echo "== healthcheck"
check "healthy" "$BIN/healthcheck.sh"
check "healthy is silent" test -z "$("$BIN/healthcheck.sh" 2>&1)"
FAKE_QUEUE_STATE=failed check_not "stopped worker is unhealthy" "$BIN/healthcheck.sh"
check "repeat alert suppressed" bash -c "[[ -z \$(FAKE_QUEUE_STATE=failed '$BIN/healthcheck.sh' 2>&1) ]]"
check "recovery reported once" bash -c "'$BIN/healthcheck.sh' | grep -q RECOVERED"
FAKE_HEALTH=503 check_not "readyz 503 is unhealthy" "$BIN/healthcheck.sh"
"$BIN/healthcheck.sh" >/dev/null 2>&1 || true

echo "== provision-database (dry run)"
out="$("$BIN/provision-database.sh" 2>&1)"
check "dry run masks passwords" grep -q "IDENTIFIED BY '\*\*\*\*\*\*\*\*'" <<<"$out"
check "no global grants" bash -c "! grep -q 'ON \*\.\*' <<<\"\$1\"" _ "$out"
check "grants scoped to DB_NAME" grep -q 'ON `nunezorganizer`.\* TO' <<<"$out"

echo "== audit script"
check "audit runs and writes a report" bash -c 'cd "$1" && shift && bash "$@"' _ "$T" "$DEPLOY/audit/vps-audit.sh" --out "$T/audit.txt" --domain localhost
check "report is 600" test "$(stat -c %a "$T/audit.txt")" = 600
check "report has sections" grep -q '==================== PHP ====================' "$T/audit.txt"

echo
echo "== setup.php (deploy/tests/setup.sh)"
# Clean environment: this suite's stubs (fake php, curl, ...) must not leak in.
if env -i HOME="$HOME" PATH="$ORIG_PATH" bash "$HERE/setup.sh" > "$T/setup-tests.log" 2>&1; then
  ok "setup.php suite ($(tail -n1 "$T/setup-tests.log" | sed 's/^setup.php — //'))"
else
  grep -E 'FAIL|passed' "$T/setup-tests.log" | sed 's/^/    /'
  bad "setup.php suite"
fi

echo
echo "passed: $pass  failed: $fail"
[[ $fail -eq 0 ]]
