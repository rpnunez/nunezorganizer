#!/usr/bin/env bash
# Offline tests for setup.php (local, production, --check, --dry-run).
# composer, artisan, npm and node are stubs that log their arguments; no database
# or network is needed (NUNEZ_SETUP_SKIP_DB_CHECK=1).
#
#   bash deploy/tests/setup.sh        (also called by deploy/tests/run.sh)

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"

pass=0; fail=0
ok()   { printf '  ok   %s\n' "$1"; pass=$((pass + 1)); }
bad()  { printf '  FAIL %s\n' "$1"; fail=$((fail + 1)); }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; fi; }
check_not() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d"; else ok "$d"; fi; }

command -v php >/dev/null || { echo "  skip php not installed"; exit 0; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
CALLS="$T/calls.log"
STUBS="$T/stubs"; mkdir -p "$STUBS"
export CALLS NUNEZ_SETUP_SKIP_DB_CHECK=1 NO_COLOR=1
# Run on whatever PHP the test host has; the 8.4 check itself is tested below.
export NUNEZ_SETUP_MIN_PHP_ID=80100
unset NUNEZ_IN_DOCKER NUNEZ_DB_PASSWORD

cat > "$STUBS/composer" <<'EOF'
#!/usr/bin/env bash
echo "composer $*" >> "$CALLS"
EOF
cat > "$STUBS/npm" <<'EOF'
#!/usr/bin/env bash
echo "npm $*" >> "$CALLS"
EOF
cat > "$STUBS/node" <<'EOF'
#!/usr/bin/env bash
echo "v${FAKE_NODE:-22.18.1}"
EOF
chmod +x "$STUBS"/*
export PATH="$STUBS:$PATH"

# A minimal project directory with the files setup.php reads.
fresh() {
  P="$T/app-$RANDOM$RANDOM"
  mkdir -p "$P/storage" "$P/bootstrap/cache" "$P/deploy/templates/env"
  cp "$REPO/setup.php" "$REPO/.env.example" "$P/"
  cp "$REPO/deploy/templates/env/production.env.tpl" "$P/deploy/templates/env/"
  cat > "$P/artisan" <<'EOF'
<?php file_put_contents(getenv('CALLS'), 'artisan '.implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
EOF
  : > "$CALLS"
}
setup() { (cd "$P" && php setup.php --composer="$STUBS/composer" "$@"); }
envval() { grep -E "^$1=" "$P/.env" | tail -n1 | cut -d= -f2-; }

echo "== setup.php: basics"
fresh
check "php -l" php -l "$REPO/setup.php"
check "--help exits 0" bash -c "cd '$P' && php setup.php --help | grep -q 'Usage: php setup.php'"
check_not "unknown option rejected" bash -c "cd '$P' && php setup.php --bogus"
check_not "bad --env rejected" bash -c "cd '$P' && php setup.php --env=staging"
out="$(cd "$P" && NUNEZ_SETUP_MIN_PHP_ID=999999 php setup.php -n 2>&1)"; rc=$?
if [[ $rc -ne 0 && "$out" == *"needs PHP 8.4+"* ]]; then ok "too-old PHP rejected with a clear message"; else bad "too-old PHP rejected with a clear message"; fi
FAKE_NODE=20.11.0 check_not "Node < 22.18 rejected (native local)" setup -n
check_not "missing composer rejected" bash -c "cd '$P' && PATH=/usr/bin:/bin php setup.php -n --composer=/nonexistent/composer"

echo "== setup.php: local (native)"
fresh
check "local setup succeeds" setup -n
check ".env created" test -f "$P/.env"
check "APP_KEY generated" grep -qE '^APP_KEY=base64:.{40,}$' "$P/.env"
check "native: DB_HOST -> 127.0.0.1" test "$(envval DB_HOST)" = "127.0.0.1"
check "native: MAIL_MAILER -> log" test "$(envval MAIL_MAILER)" = "log"
check "composer install (with dev)" grep -qx 'composer install --no-interaction --prefer-dist --no-progress' "$CALLS"
check "artisan migrate" grep -qx 'artisan migrate --no-interaction' "$CALLS"
check "artisan db:seed (demo users)" grep -qx 'artisan db:seed --no-interaction' "$CALLS"
check "npm ci + build" bash -c "grep -q '^npm ci' '$CALLS' && grep -qx 'npm run build' '$CALLS'"
key1="$(envval APP_KEY)"; : > "$CALLS"
check "re-run succeeds" setup -n --skip-seed
check "re-run keeps APP_KEY" test "$(envval APP_KEY)" = "$key1"
check_not "--skip-seed skips db:seed" grep -q 'db:seed' "$CALLS"
: > "$CALLS"
check "--skip-migrate --skip-assets" setup -n --skip-migrate --skip-assets
check_not "no migrate with --skip-migrate" grep -q 'migrate' "$CALLS"
check_not "no npm with --skip-assets" grep -q '^npm' "$CALLS"
check "--db-* flags update .env" setup -n --skip-migrate --skip-assets --db-host=db.local --db-port=3307 --db-name=nz --db-user=nzu
check "flag values written" bash -c "[[ \$(grep -c -E '^(DB_HOST=db.local|DB_PORT=3307|DB_DATABASE=nz|DB_USERNAME=nzu)\$' '$P/.env') -eq 4 ]]"

echo "== setup.php: local (Docker)"
fresh
check "Docker local setup succeeds" env NUNEZ_IN_DOCKER=1 bash -c "cd '$P' && php setup.php -n --composer='$STUBS/composer'"
check "Docker: DB_HOST stays mariadb" test "$(envval DB_HOST)" = "mariadb"
check_not "Docker: npm left to the node container" grep -q '^npm' "$CALLS"
check "Docker: Node not required" env NUNEZ_IN_DOCKER=1 FAKE_NODE=18.0.0 bash -c "cd '$P' && php setup.php -n --composer='$STUBS/composer' --skip-migrate"

echo "== setup.php: dry run"
fresh
check "--dry-run succeeds" setup -n --dry-run
check "--dry-run writes no .env" test ! -e "$P/.env"
check "--dry-run runs no commands" test ! -s "$CALLS"

echo "== setup.php: production"
fresh
check_not "production needs a DB password" setup --env=production -n --app-url=https://nz.example.test --skip-assets
check_not "production needs APP_URL" env NUNEZ_DB_PASSWORD=Str0ngPassw0rd bash -c "cd '$P' && php setup.php --env=production -n --composer='$STUBS/composer' --skip-assets"
check "production install" env NUNEZ_DB_PASSWORD='Str0ng pa$$ "word"' bash -c "cd '$P' && php setup.php --env=production -n --force --composer='$STUBS/composer' --app-url=https://nz.example.test/ --db-name=nzprod --db-user=nzprod"
check ".env mode 600" test "$(stat -c %a "$P/.env")" = 600
check "APP_ENV=production" test "$(envval APP_ENV)" = production
check "APP_DEBUG=false" test "$(envval APP_DEBUG)" = false
check "APP_URL trailing slash trimmed" test "$(envval APP_URL)" = https://nz.example.test
check "DB values from flags" bash -c "grep -qx 'DB_DATABASE=nzprod' '$P/.env' && grep -qx 'DB_USERNAME=nzprod' '$P/.env'"
check "password quoted safely in .env" grep -qxF 'DB_PASSWORD="Str0ng pa\$\$ \"word\""' "$P/.env"
check "password never printed" bash -c "! env NUNEZ_DB_PASSWORD=Str0ngPassw0rd123 bash -c \"cd '$P' && rm -f .env && php setup.php --env=production -n --force --composer='$STUBS/composer' --app-url=https://nz.example.test --skip-assets\" 2>&1 | grep -q Str0ngPassw0rd123"
check "no unrendered placeholders" bash -c "! grep -q '{{' '$P/.env'"
check "MAIL_FROM defaults to app host" grep -q '^MAIL_FROM_ADDRESS="\?no-reply@nz.example.test' "$P/.env"
check "composer --no-dev" grep -q 'composer install --no-dev --optimize-autoloader' "$CALLS"
check "migrate --force with --force" grep -qx 'artisan migrate --force --no-interaction' "$CALLS"
check "artisan optimize" grep -qx 'artisan optimize --no-interaction' "$CALLS"
check_not "no db:seed in production" grep -q 'db:seed' "$CALLS"
: > "$CALLS"
check "re-run without --force (non-interactive)" setup --env=production -n --skip-assets
check_not "migrations skipped without --force" grep -q 'migrate' "$CALLS"
echo 'APP_DEBUG=true' >> "$P/.env"
check_not "unsafe existing .env refused" setup --env=production -n --skip-assets --skip-migrate
fresh
check_not "http APP_URL refused" env NUNEZ_DB_PASSWORD=Str0ngPassw0rd bash -c "cd '$P' && php setup.php --env=production -n --composer='$STUBS/composer' --app-url=http://nz.example.test --skip-assets"
fresh
check "--allow-http accepted" env NUNEZ_DB_PASSWORD=Str0ngPassw0rd bash -c "cd '$P' && php setup.php --env=production -n --allow-http --composer='$STUBS/composer' --app-url=http://nz.example.test --skip-assets --skip-migrate"
fresh
check_not "placeholder DB password refused" env NUNEZ_DB_PASSWORD=secret bash -c "cd '$P' && php setup.php --env=production -n --composer='$STUBS/composer' --app-url=https://nz.example.test --skip-assets --skip-migrate"

echo "== setup.php: .env round trip (what setup writes, its own DB check reads back)"
cat > "$T/parse.php" <<'EOF'
<?php
// Load setup.php's functions without running it.
$src = file_get_contents($argv[1]);
$src = preg_replace('/^exit\(nunez_main\(.*$/m', '', $src);
eval('?>' . $src);
foreach (array('Str0ng pa$$ "word"', 'a${HOME}b', 'back\\slash #hash', "it's", 'plain') as $pw) {
    file_put_contents($argv[2], 'DB_PASSWORD=' . nunez_env_quote($pw) . "\n");
    $got = nunez_read_env($argv[2]);
    if ($got['DB_PASSWORD'] !== $pw) { fwrite(STDERR, "mismatch: {$pw} -> {$got['DB_PASSWORD']}\n"); exit(1); }
}
EOF
check "nunez_env_quote -> nunez_read_env round-trips special characters" php "$T/parse.php" "$REPO/setup.php" "$T/rt.env"

echo "== setup.php: --validate-env (used by deploy/bin/check-env.sh)"
fresh
env NUNEZ_DB_PASSWORD=Str0ngPassw0rd bash -c "cd '$P' && php setup.php --env=production -n --composer='$STUBS/composer' --app-url=https://nz.example.test --skip-assets --skip-migrate" >/dev/null 2>&1
check "valid production .env passes" bash -c "cd / && php '$P/setup.php' --validate-env='$P/.env'"
for bad_line in 'APP_DEBUG=true' 'SESSION_HTTP_ONLY=false' 'DB_PASSWORD=CHANGE_ME' 'DB_HOST=mariadb' 'DB_USERNAME=root'; do
  cp "$P/.env" "$T/bad.env"; echo "$bad_line" >> "$T/bad.env"
  check_not "--validate-env rejects $bad_line" php "$P/setup.php" --validate-env="$T/bad.env"
done
check_not "--validate-env missing file" php "$P/setup.php" --validate-env="$T/nope.env"
if php "$P/setup.php" --validate-env="$P/.env" 2>&1 | grep -q Str0ngPassw0rd; then bad "--validate-env never prints values"; else ok "--validate-env never prints values"; fi

echo "== setup.php: --check"
fresh
check_not "--check fails without .env/vendor/assets" setup --check
out="$(cd "$P" && php setup.php --check 2>&1)"
if [[ "$out" == *".env missing"* && "$out" == *"vendor/ missing"* ]]; then ok "--check lists the problems"; else bad "--check lists the problems"; fi
check "--check changed nothing" test ! -e "$P/.env"
mkdir -p "$P/vendor" "$P/public/build"; touch "$P/vendor/autoload.php"; echo '{}' > "$P/public/build/manifest.json"
setup -n --skip-migrate --skip-assets >/dev/null 2>&1
check "--check passes on a complete local install" setup --check

echo
echo "setup.php — passed: $pass  failed: $fail"
[[ $fail -eq 0 ]]
