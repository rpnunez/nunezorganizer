; Alternative to the systemd unit, if QUEUE_SUPERVISOR=supervisor (issue #21).
; Install in /etc/supervisord.d/{{QUEUE_SERVICE}}.ini (RHEL family) or
; /etc/supervisor/conf.d/{{QUEUE_SERVICE}}.conf (Debian family), then
;   supervisorctl reread && supervisorctl update
; supervisord itself must be enabled at boot (systemctl enable supervisord).

[program:{{QUEUE_SERVICE}}]
process_name=%(program_name)s_%(process_num)02d
command={{PHP_BIN}} {{APP_ROOT}}/current/artisan queue:work {{QUEUE_CONNECTION}} --queue={{QUEUE_NAMES}} --sleep=3 --tries=3 --max-time={{QUEUE_MAX_TIME}} --memory={{QUEUE_MEMORY_MB}}
directory={{APP_ROOT}}/current
user={{APP_USER}}
numprocs=1
autostart=true
autorestart=true
startsecs=3
stopsignal=TERM
stopwaitsecs=90
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile={{APP_ROOT}}/shared/logs/queue-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
