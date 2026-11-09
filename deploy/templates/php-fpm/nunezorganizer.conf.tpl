; PHP-FPM pool for NunezOrganizer (issue #21): PHP runs as the app user, never as
; www-data/nobody or another site's user. Install as
; /etc/php/8.4/fpm/pool.d/{{APP_SLUG}}.conf (Debian/Ubuntu) or /etc/php-fpm.d/ (RHEL),
; then: systemctl reload php8.4-fpm
[{{APP_SLUG}}]
user = {{APP_USER}}
group = {{APP_GROUP}}
listen = {{PHP_FPM_SOCKET}}
; The web server user must be able to connect to the socket (www-data on Debian,
; nginx/apache on RHEL).
listen.owner = {{APP_USER}}
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500

; Keep secrets out of phpinfo()/error pages; logs go to Laravel's storage/logs.
php_admin_value[expose_php] = Off
php_admin_flag[display_errors] = off
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
