# OpenLiteSpeed virtual host for NunezOrganizer (issue #21).
# Install at {{OLS_ROOT}}/conf/vhosts/{{OLS_VHOST_NAME}}/vhconf.conf (owner lsadm:nobody, 0640),
# or reproduce these settings in the Webuzo/OLS WebAdmin UI. See docs/runbook.md#web-server.

docRoot                   {{APP_ROOT}}/current/public/
vhDomain                  {{APP_DOMAIN}}
adminEmails               root@localhost
enableGzip                1
enableBr                  1

errorlog {{OLS_LOG_DIR}}/error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
  keepDays                14
}

accesslog {{OLS_LOG_DIR}}/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\""
  rollingSize             10M
  keepDays                14
  compressArchive         1
}

index  {
  useServer               0
  indexFiles              index.php
  autoIndex               0
}

# PHP 8.4 via LSAPI, running as the dedicated app user (never nobody, never the WordPress user).
extprocessor {{OLS_EXTAPP_NAME}} {
  type                    lsapi
  address                 uds://tmp/lshttpd/{{OLS_EXTAPP_NAME}}.sock
  maxConns                {{PHP_LSAPI_CHILDREN}}
  env                     PHP_LSAPI_CHILDREN={{PHP_LSAPI_CHILDREN}}
  env                     LSAPI_AVOID_FORK=200M
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    {{LSPHP_BIN}}
  backlog                 100
  instances               1
  extUser                 {{APP_USER}}
  extGroup                {{APP_GROUP}}
  runOnStartUp            3
  priority                0
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}

scripthandler  {
  add                     lsapi:{{OLS_EXTAPP_NAME}} php
}

# Security headers for every response (Laravel may add more per route).
context / {
  location                {{APP_ROOT}}/current/public/
  allowBrowse             1
  extraHeaders            <<<END_extraHeaders
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
  END_extraHeaders
  rewrite  {
    enable                1
  }
  addDefaultCharset       off
}

# Never serve dotfiles (except ACME challenges) even if one lands in public/.
context exp:^/\.(?!well-known/) {
  allowBrowse             0
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
  logLevel                0
  # HTTPS only. Let ACME HTTP-01 challenges through on port 80.
  rules                   <<<END_rules
RewriteCond %{HTTPS} !=on
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
  END_rules
}

vhssl  {
  keyFile                 {{SSL_KEY_FILE}}
  certFile                {{SSL_CERT_FILE}}
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableStapling          1
  ocspRespMaxAge          86400
}
