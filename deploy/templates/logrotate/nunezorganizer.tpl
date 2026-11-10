# /etc/logrotate.d/{{APP_SLUG}} — Laravel and deploy logs (issue #21).
# Use LOG_STACK=single in .env so Laravel writes one file and logrotate rotates it.
{{APP_ROOT}}/shared/storage/logs/*.log {{APP_ROOT}}/shared/logs/*.log {
    su {{APP_USER}} {{APP_GROUP}}
    daily
    rotate 14
    maxsize 50M
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    create 0640 {{APP_USER}} {{APP_GROUP}}
}
