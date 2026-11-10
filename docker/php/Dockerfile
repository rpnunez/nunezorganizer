# NunezOrganizer development / test image: PHP 8.4 FPM + Composer + Xdebug 3.
# Used by the app, queue and scheduler services in docker-compose.yml.
FROM php:8.4-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip libzip-dev libicu-dev mariadb-client $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql intl zip bcmath pcntl opcache \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY conf.d/ /usr/local/etc/php/conf.d/
COPY php-fpm.d/zz-nunez.conf /usr/local/etc/php-fpm.d/zz-nunez.conf

# Run as an unprivileged user whose UID/GID match yours on Linux hosts, so files
# created in the bind mount (vendor/, storage/) stay editable. Irrelevant on Windows.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "${GID}" app 2>/dev/null || true \
 && useradd --create-home --uid "${UID}" --gid "${GID}" app \
 && mkdir -p /home/app/.composer && chown -R "${UID}:${GID}" /home/app

USER app
WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]
