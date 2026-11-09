#!/usr/bin/env bash
# NunezOrganizer — read-only VPS audit (issue #21, step 1).
#
# Collects what the deployment artifacts need to know about the server:
# OS, Webuzo, OpenLiteSpeed (vhosts, listeners, PHP handlers), LSPHP versions
# and extensions, MariaDB, Composer/Node, process managers, cron, TLS,
# firewall, existing WordPress footprint and outbound connectivity.
#
# READ-ONLY: this script never writes outside its own report file, never
# restarts anything, never reads wp-config.php / .env contents, and never
# prints passwords, keys or certificates (only paths, versions and expiry).
#
# Usage (as root for full results; non-root works but sees less):
#   sudo bash vps-audit.sh [--domain organizer.example.com] [--app-user nunezorg] [--no-mask-ip] [--out FILE]
#
# Then review the report and send it back. Public IPv4 addresses are masked by
# default (the script tells you whether the --domain resolves to this server).

set -u
set -o pipefail
export LC_ALL=C

DOMAIN=""
APP_USER="nunezorg"
MASK_IP=1
OUT=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --app-user) APP_USER="${2:-}"; shift 2 ;;
    --no-mask-ip) MASK_IP=0; shift ;;
    --out) OUT="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

TS="$(date -u +%Y%m%dT%H%M%SZ)"
[[ -n "$OUT" ]] || OUT="./nunez-vps-audit-${TS}.txt"
umask 077
: > "$OUT" || { echo "cannot write $OUT" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }
TO=""; have timeout && TO="timeout 15"

# Redact anything that looks like a secret, and (optionally) public IPv4s.
# Loopback, 0.0.0.0 and RFC1918 addresses are kept; everything else -> x.x.x.x
mask_ips() {
  if [[ $MASK_IP -eq 0 ]]; then cat; return; fi
  if have perl; then
    perl -pe 's/(?<![\d.])(\d{1,3}(?:\.\d{1,3}){3})(?![\d.])/ do { my $ip = $1; ($ip =~ m{^(127\.|0\.0\.0\.0$|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)}) ? $ip : "x.x.x.x" } /ge'
  else
    # no perl: mask every IPv4 except 127.0.0.1 / 0.0.0.0
    sed -E -e 's/127\.0\.0\.1/@LO@/g; s/0\.0\.0\.0/@ANY@/g' \
      -e 's/[0-9]{1,3}(\.[0-9]{1,3}){3}/x.x.x.x/g' \
      -e 's/@LO@/127.0.0.1/g; s/@ANY@/0.0.0.0/g'
  fi
}
redact() {
  sed -E \
    -e 's/((pass(word)?|passwd|secret|token|api[_-]?key)[A-Za-z_]*[[:space:]]*[=:][[:space:]]*)[^[:space:]]+/\1[REDACTED]/Ig' \
    -e 's#(bot)[0-9]{6,}:[A-Za-z0-9_-]{20,}#\1[REDACTED]#g' \
    -e 's#(Bearer|Basic) [A-Za-z0-9._~+/=-]{8,}#\1 [REDACTED]#g' | mask_ips
}

out() { printf '%s\n' "$*" | redact >> "$OUT"; }
section() { printf '\n==================== %s ====================\n' "$*" >> "$OUT"; echo ">> $*" >&2; }
# run "label" cmd...  — runs a read-only command with a timeout, captures stdout+stderr
run() {
  local label="$1"; shift
  printf -- '--- %s\n$ %s\n' "$label" "$*" >> "$OUT"
  # shellcheck disable=SC2086
  $TO "$@" 2>&1 | head -n 400 | redact >> "$OUT"
  printf '[exit %s]\n' "${PIPESTATUS[0]}" >> "$OUT"
}
kv() { printf '%-34s %s\n' "$1" "$2" | redact >> "$OUT"; }

IS_ROOT=0; [[ $EUID -eq 0 ]] && IS_ROOT=1

section "META"
kv "audit_version" "1"
kv "generated_utc" "$TS"
kv "running_as_root" "$IS_ROOT"
kv "hostname_short" "$(hostname -s 2>/dev/null || hostname)"
kv "uptime" "$(uptime 2>/dev/null)"
[[ $IS_ROOT -eq 1 ]] || out "NOTE: not root — vhost configs, DB grants and some services may be hidden. Re-run with sudo for full results."

section "OS"
run "os-release" cat /etc/os-release
run "kernel" uname -srmo
have systemd-detect-virt && run "virtualization" systemd-detect-virt
have getenforce && run "selinux" getenforce
have aa-status && run "apparmor" aa-status --enabled
run "timedatectl" timedatectl
kv "TZ (/etc/localtime)" "$(readlink -f /etc/localtime 2>/dev/null)"

section "RESOURCES"
run "cpus" nproc
run "memory" free -m
run "disk" df -hT / /home /var /usr/local /tmp
run "inodes" df -i / /home

section "USERS"
if id "$APP_USER" >/dev/null 2>&1; then
  kv "app_user ($APP_USER)" "EXISTS: $(id "$APP_USER")"
  kv "app_user home" "$(getent passwd "$APP_USER" | cut -d: -f6)"
  kv "app_user shell" "$(getent passwd "$APP_USER" | cut -d: -f7)"
else
  kv "app_user ($APP_USER)" "does not exist yet"
fi
run "human users (uid>=1000, names/home/shell)" awk -F: '$3>=1000 && $3<65534 {print $1, $6, $7}' /etc/passwd
kv "sudo installed" "$(have sudo && echo yes || echo no)"
[[ -d /etc/sudoers.d ]] && run "sudoers.d entries (names only)" ls -1 /etc/sudoers.d

section "WEBUZO"
if [[ -d /usr/local/webuzo ]]; then
  kv "webuzo_dir" "/usr/local/webuzo present"
  run "webuzo top-level" ls -la /usr/local/webuzo
  for f in /usr/local/webuzo/version.txt /usr/local/webuzo/version /usr/local/webuzo/conf/version; do
    [[ -f "$f" ]] && run "webuzo version file $f" head -c 200 "$f"
  done
  run "webuzo version-like files" find /usr/local/webuzo -maxdepth 3 -iname '*version*' -type f
  [[ -d /usr/local/apps ]] && run "/usr/local/apps (webuzo-installed apps)" ls -la /usr/local/apps
  [[ -d /var/webuzo ]] && run "/var/webuzo" ls -la /var/webuzo
  [[ -d /var/webuzo/users ]] && run "webuzo users dir" ls -1 /var/webuzo/users
else
  kv "webuzo_dir" "not found at /usr/local/webuzo"
fi

section "WEB SERVER"
if have ss; then run "listening TCP ports (process names)" ss -tlnp
elif have netstat; then run "listening TCP ports (process names)" netstat -tlnp
else kv "listening TCP ports" "neither ss nor netstat available"; fi
for b in /usr/local/lsws/bin/openlitespeed /usr/local/lsws/bin/lshttpd; do
  [[ -x "$b" ]] && run "OLS version ($b)" "$b" -v
done
[[ -f /usr/local/lsws/VERSION ]] && run "OLS VERSION file" cat /usr/local/lsws/VERSION
have systemctl && run "lsws service" systemctl status lsws --no-pager -n 0
have systemctl && run "lshttpd service" systemctl status lshttpd --no-pager -n 0
for svc in httpd apache2 nginx; do
  have systemctl && systemctl list-unit-files "${svc}.service" 2>/dev/null | grep -q "$svc" && run "$svc service" systemctl status "$svc" --no-pager -n 0
done

OLS_CONF=/usr/local/lsws/conf/httpd_config.conf
if [[ -r "$OLS_CONF" ]]; then
  run "OLS httpd_config: server-level keys" grep -nE '^\s*(serverName|user|group|enableLVE|cpuAffinity|autoRestart|adminEmails|inMemBufSize|enableChroot)\b' "$OLS_CONF"
  run "OLS httpd_config: listeners / vhosts / maps / extprocessors / templates (structure only)" \
    grep -nE '^\s*(listener|virtualhost|vhTemplate|extprocessor|map|address|secure|vhRoot|configFile|templateFile|member|path|extUser|extGroup|setUIDMode|allowSymbolLink|restrained|enableScript|keyFile|certFile)\b|^\s*\}' "$OLS_CONF"
  run "OLS include files" grep -nE '^\s*include\b' "$OLS_CONF"
else
  kv "OLS httpd_config" "not readable at $OLS_CONF"
fi
[[ -d /usr/local/lsws/conf/vhosts ]] && run "OLS vhosts dir" ls -la /usr/local/lsws/conf/vhosts
[[ -d /usr/local/lsws/conf/templates ]] && run "OLS templates dir" ls -la /usr/local/lsws/conf/templates

# Per-vhost config: docroot, domains, php handler, rewrite, ssl paths (never key contents).
mapfile -t VHCONFS < <(
  { find /usr/local/lsws/conf/vhosts -maxdepth 3 -name '*.conf' 2>/dev/null
    grep -hoE '^\s*configFile\s+\S+' "$OLS_CONF" 2>/dev/null | awk '{print $2}' | sed 's#\$SERVER_ROOT#/usr/local/lsws#'
  } | sort -u)
for vc in "${VHCONFS[@]}"; do
  [[ -r "$vc" ]] || continue
  run "vhost config $vc (selected keys)" grep -nE '^\s*(docRoot|vhDomain|vhAliases|adminEmails|enableGzip|enableBr|add|path|extUser|extGroup|address|maxConns|PHP_LSAPI_CHILDREN|env|enable|autoLoadHtaccess|keyFile|certFile|certChain|sslProtocol|errorlog|accesslog|context|location|allowBrowse|extraHeaders|setUIDMode|phpIniOverride)\b|^\s*(scripthandler|extprocessor|rewrite|vhssl|context|index)\b' "$vc"
done

section "TLS CERTIFICATES"
mapfile -t CERTS < <(grep -rhoE '^\s*certFile\s+\S+' /usr/local/lsws/conf 2>/dev/null | awk '{print $2}' | sort -u)
if [[ ${#CERTS[@]} -gt 0 ]] && have openssl; then
  for c in "${CERTS[@]}"; do
    [[ -r "$c" ]] || { kv "cert $c" "not readable"; continue; }
    run "cert $c (subject / SAN / expiry)" bash -c "openssl x509 -in '$c' -noout -subject -enddate -issuer; openssl x509 -in '$c' -noout -ext subjectAltName 2>/dev/null"
  done
else
  kv "certFile entries" "none found or openssl missing"
fi
for t in certbot acme.sh; do have "$t" && kv "$t" "$(command -v "$t")"; done
for d in /root/.acme.sh /usr/local/webuzo/acme.sh /etc/letsencrypt/live; do
  [[ -d "$d" ]] && run "acme dir $d (names only)" ls -1 "$d"
done

section "PHP"
mapfile -t PHPS < <(
  { ls -1 /usr/local/lsws/lsphp*/bin/php 2>/dev/null
    ls -1 /usr/local/apps/php*/bin/php 2>/dev/null
    ls -1 /usr/bin/php[0-9]* /usr/bin/php 2>/dev/null
    ls -1 /opt/*/php*/bin/php /opt/alt/php*/usr/bin/php 2>/dev/null
    command -v php 2>/dev/null
  } | xargs -r -n1 readlink -f 2>/dev/null | sort -u)
kv "php on PATH" "$(command -v php 2>/dev/null || echo none)"
REQ_EXT="bcmath ctype curl dom fileinfo filter hash intl json mbstring openssl pcntl pcre pdo pdo_mysql session sodium tokenizer xml zip"
for p in "${PHPS[@]}"; do
  [[ -x "$p" ]] || continue
  run "PHP $p version" "$p" -v
  mods="$($TO "$p" -m 2>/dev/null | tr 'A-Z' 'a-z')"
  missing=""
  for e in $REQ_EXT; do grep -qx "$e" <<<"$mods" || missing+="$e "; done
  kv "  required ext missing" "${missing:-none}"
  kv "  opcache loaded" "$(grep -qx 'zend opcache' <<<"$mods" && echo yes || echo no)"
  run "PHP $p ini (selected)" "$p" -r 'foreach (["memory_limit","max_execution_time","upload_max_filesize","post_max_size","date.timezone","opcache.enable","opcache.enable_cli","opcache.validate_timestamps","opcache.revalidate_freq","realpath_cache_ttl","disable_functions","open_basedir","expose_php","pdo_mysql.default_socket","session.save_handler"] as $k) printf("%-28s %s\n", $k, var_export(ini_get($k), true)); echo "loaded ini: ", php_ini_loaded_file(), PHP_EOL;'
done
for l in /usr/local/lsws/lsphp*/bin/lsphp /usr/local/apps/php*/bin/lsphp; do
  [[ -x "$l" ]] && kv "lsphp binary" "$l"
done

section "DATABASE (MariaDB/MySQL)"
for b in mariadbd mysqld /usr/sbin/mariadbd /usr/sbin/mysqld /usr/local/mysql/bin/mysqld; do
  have "$b" && { run "server binary $b" "$b" --version; break; }
done
for b in mariadb mysql; do have "$b" && run "client $b" "$b" --version; done
for b in mariadb-dump mysqldump; do have "$b" && kv "dump tool" "$(command -v "$b")"; done
if have systemctl; then
  for s in mariadb mysql mysqld; do
    systemctl list-unit-files "${s}.service" 2>/dev/null | grep -q "^${s}.service" && run "service $s" systemctl status "$s" --no-pager -n 0
  done
fi
run "DB config keys (bind/socket/port/sql_mode/tz/charset/innodb)" bash -c "grep -rhnE '^\s*(bind-address|bind_address|socket|port|skip-networking|sql[-_]mode|default[-_]time[-_]zone|character[-_]set[-_]server|collation[-_]server|innodb[-_]buffer[-_]pool[-_]size|max[-_]connections|log[-_]bin|local[-_]infile)\b' /etc/my.cnf /etc/my.cnf.d /etc/mysql 2>/dev/null"

DBCLI=""; have mariadb && DBCLI=mariadb; [[ -z "$DBCLI" ]] && have mysql && DBCLI=mysql
if [[ -n "$DBCLI" && $IS_ROOT -eq 1 ]]; then
  # No -p: uses unix-socket auth only, never prompts. If it fails we just report that.
  if $TO "$DBCLI" --connect-timeout=5 -NBe 'SELECT 1' </dev/null >/dev/null 2>&1; then
    kv "root socket auth" "works (queries below are read-only)"
    run "server version / tz / sql_mode / charset" "$DBCLI" -NBe "SELECT VERSION(), @@version_comment, @@global.time_zone, @@system_time_zone, @@global.sql_mode, @@character_set_server, @@collation_server, @@bind_address, @@port, @@socket, @@innodb_buffer_pool_size, @@max_connections"
    run "tz tables loaded (rows in mysql.time_zone_name)" "$DBCLI" -NBe "SELECT COUNT(*) FROM mysql.time_zone_name"
    run "databases (names only)" "$DBCLI" -NBe "SHOW DATABASES"
    run "accounts with global power (user@host, Super/Grant/File/Process)" "$DBCLI" -NBe "SELECT CONCAT(user,'@',host), Super_priv, Grant_priv, File_priv, Process_priv FROM mysql.user ORDER BY user"
    run "existing nunezorganizer db/users?" "$DBCLI" -NBe "SELECT 'db', SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'nunez%' UNION ALL SELECT 'user', CONCAT(user,'@',host) FROM mysql.user WHERE user LIKE 'nunez%'"
    run "per-database grants (who can touch which schema)" "$DBCLI" -NBe "SELECT CONCAT(User,'@',Host), Db FROM mysql.db ORDER BY Db, User"
  else
    kv "root socket auth" "not available without a password (skipped DB queries — that's fine)"
  fi
fi

section "TOOLCHAIN"
for t in composer node npm npx git unzip zip curl wget rsync rclone gzip zstd gpg age openssl flock logrotate envsubst jq mail sendmail; do
  if have "$t"; then
    case "$t" in
      openssl) v="$(openssl version 2>&1)" ;;
      unzip|zip) v="$($TO "$t" -v 2>&1 | head -n1)" ;;
      mail|sendmail|logrotate) v="" ;;
      *) v="$($TO "$t" --version 2>&1 | head -n1)" ;;
    esac
    [[ "$t" == "composer" ]] && v="$(COMPOSER_ALLOW_SUPERUSER=1 $TO composer --version --no-interaction 2>&1 | head -n1)"
    kv "$t" "$(command -v "$t") :: $v"
  else
    kv "$t" "MISSING"
  fi
done
[[ -d /root/.nvm || -d "/home/$APP_USER/.nvm" ]] && kv "nvm" "present"

section "PROCESS MANAGEMENT"
have systemctl && kv "systemd" "$(systemctl --version | head -n1)"
have supervisorctl && run "supervisor" supervisorctl version
[[ -d /etc/supervisord.d ]] && run "supervisord.d" ls -1 /etc/supervisord.d
[[ -d /etc/supervisor/conf.d ]] && run "supervisor conf.d" ls -1 /etc/supervisor/conf.d
have systemctl && run "failed units" systemctl --failed --no-pager
have loginctl && id "$APP_USER" >/dev/null 2>&1 && run "linger for $APP_USER" loginctl show-user "$APP_USER" -p Linger

section "CRON"
for s in crond cron; do
  have systemctl && systemctl list-unit-files "${s}.service" 2>/dev/null | grep -q "^${s}.service" && kv "cron service ($s)" "$(systemctl is-active "$s" 2>&1)"
done
[[ -d /etc/cron.d ]] && run "/etc/cron.d (names only)" ls -1 /etc/cron.d
[[ $IS_ROOT -eq 1 ]] && kv "root crontab line count" "$(crontab -l 2>/dev/null | grep -cvE '^\s*(#|$)')"
[[ $IS_ROOT -eq 1 ]] && kv "any existing schedule:run" "$(grep -rls 'schedule:run' /etc/cron.d /var/spool/cron 2>/dev/null | tr '\n' ' ' || true)"
[[ -d /etc/logrotate.d ]] && run "/etc/logrotate.d (names only)" ls -1 /etc/logrotate.d

section "FIREWALL / SECURITY"
have csf && run "csf" csf -v
have firewall-cmd && run "firewalld" firewall-cmd --state
have firewall-cmd && run "firewalld services/ports" firewall-cmd --list-all
have ufw && run "ufw" ufw status
have fail2ban-client && run "fail2ban" fail2ban-client status
[[ -r /etc/ssh/sshd_config ]] && run "sshd auth settings" grep -nE '^\s*(PermitRootLogin|PasswordAuthentication|Port)\b' /etc/ssh/sshd_config

section "MAIL (outbound SMTP for Laravel)"
for m in postfix exim exim4 sendmail; do have "$m" && kv "$m" "$(command -v "$m")"; done
run "listening on 25/465/587" bash -c "{ ss -tln 2>/dev/null || netstat -tln 2>/dev/null; } | grep -E ':(25|465|587)\b' || echo none"

section "WORDPRESS FOOTPRINT (paths and owners only; contents are NOT read)"
if [[ $IS_ROOT -eq 1 ]]; then
  run "wp-config.php locations" bash -c "find /home /var/www /usr/local/lsws -maxdepth 6 -name wp-config.php -printf '%u:%g %m %p\n' 2>/dev/null"
else
  run "wp-config.php locations (visible to this user)" bash -c "find /home /var/www -maxdepth 6 -name wp-config.php -printf '%u:%g %m %p\n' 2>/dev/null"
fi

section "DNS / CONNECTIVITY"
SERVER_IPS="$(hostname -I 2>/dev/null; ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1)"
PUBLIC_IP="$($TO curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"
if [[ -n "$DOMAIN" ]]; then
  resolved="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')"
  kv "domain" "$DOMAIN"
  if [[ -z "$resolved" ]]; then
    kv "domain resolves" "NO (no A record yet)"
  else
    match=no
    for r in $resolved; do grep -qw "$r" <<<"$SERVER_IPS $PUBLIC_IP" && match=yes; done
    kv "domain resolves" "yes"
    kv "domain points at this server" "$match"
  fi
else
  kv "domain" "(not given — pass --domain to check DNS)"
fi
for u in https://api.telegram.org https://github.com https://repo.packagist.org https://registry.npmjs.org; do
  code="$($TO curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$u" 2>&1 || true)"
  kv "outbound $u" "$code"
done

section "END"
out "Report: $OUT"
echo
echo "Done. Review $OUT (it is chmod 600), then send it back." >&2
echo "Public IPs are masked as x.x.x.x$( [[ $MASK_IP -eq 0 ]] && echo ' — DISABLED (--no-mask-ip)')." >&2
