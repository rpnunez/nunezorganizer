# Apache 2.4 + PHP-FPM vhost for NunezOrganizer (issue #21). Needs mod_rewrite,
# mod_ssl, mod_headers, mod_proxy_fcgi. Install as
# /etc/apache2/sites-available/{{APP_SLUG}}.conf (a2ensite) or /etc/httpd/conf.d/,
# then: apachectl configtest && systemctl reload apache2 (or httpd)

<VirtualHost *:80>
    ServerName {{APP_DOMAIN}}
    DocumentRoot {{APP_ROOT}}/current/public
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName {{APP_DOMAIN}}
    DocumentRoot {{APP_ROOT}}/current/public

    SSLEngine on
    SSLCertificateFile    {{SSL_CERT_FILE}}
    SSLCertificateKeyFile {{SSL_KEY_FILE}}
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    <Directory {{APP_ROOT}}/current/public>
        # FollowSymLinks: the docroot is reached through the `current` symlink.
        # AllowOverride All: Laravel's public/.htaccess does the front-controller rewrite.
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:{{PHP_FPM_SOCKET}}|fcgi://localhost"
    </FilesMatch>

    # Never serve dotfiles except ACME challenges.
    <LocationMatch "/\.(?!well-known)">
        Require all denied
    </LocationMatch>
</VirtualHost>
