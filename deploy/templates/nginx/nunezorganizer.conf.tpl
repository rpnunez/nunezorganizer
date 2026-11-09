# Nginx + PHP-FPM vhost for NunezOrganizer (issue #21). Install as
# /etc/nginx/sites-available/{{APP_SLUG}}.conf (Debian/Ubuntu: symlink into sites-enabled/)
# or /etc/nginx/conf.d/{{APP_SLUG}}.conf, then: nginx -t && systemctl reload nginx
# Certificates: e.g. certbot certonly --webroot -w {{APP_ROOT}}/current/public -d {{APP_DOMAIN}}

server {
    listen 80;
    listen [::]:80;
    server_name {{APP_DOMAIN}};

    # Let ACME HTTP-01 challenges through; everything else goes to HTTPS.
    location ^~ /.well-known/acme-challenge/ {
        root {{APP_ROOT}}/current/public;
    }
    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name {{APP_DOMAIN}};

    ssl_certificate     {{SSL_CERT_FILE}};
    ssl_certificate_key {{SSL_KEY_FILE}};
    ssl_protocols       TLSv1.2 TLSv1.3;

    root {{APP_ROOT}}/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20m;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:{{PHP_FPM_SOCKET}};
        include fastcgi_params;
        # $realpath_root resolves the `current` symlink, so each release is cached separately.
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
