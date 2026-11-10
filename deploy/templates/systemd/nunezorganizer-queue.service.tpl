# Laravel queue worker for NunezOrganizer (issue #21).
# Install: /etc/systemd/system/{{QUEUE_SERVICE}}.service, then
#   systemctl daemon-reload && systemctl enable --now {{QUEUE_SERVICE}}
# Releases call `artisan queue:restart`; the worker exits cleanly and systemd
# restarts it inside the new `current` release.

[Unit]
Description=NunezOrganizer queue worker ({{QUEUE_NAMES}})
After=network-online.target mariadb.service mysql.service
Wants=network-online.target
StartLimitIntervalSec=300
StartLimitBurst=20

[Service]
Type=simple
User={{APP_USER}}
Group={{APP_GROUP}}
WorkingDirectory={{APP_ROOT}}/current
ExecStart={{PHP_BIN}} {{APP_ROOT}}/current/artisan queue:work {{QUEUE_CONNECTION}} --queue={{QUEUE_NAMES}} --sleep=3 --tries=3 --max-time={{QUEUE_MAX_TIME}} --memory={{QUEUE_MEMORY_MB}}
Restart=always
RestartSec=5
# Let an in-flight job finish on stop/restart (queue:work handles SIGTERM).
KillSignal=SIGTERM
TimeoutStopSec=90

# Hardening: read-only system and code, writable shared storage only.
# Only directives supported by systemd 239 (AlmaLinux 8).
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths={{APP_ROOT}}/shared
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictRealtime=yes
LockPersonality=yes
UMask=0027

[Install]
WantedBy=multi-user.target
