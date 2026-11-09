# Local development / test image for NunezOrganizer (PHP 8.4 CLI).
FROM php:8.4-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev mariadb-client \
 && docker-php-ext-install pdo_mysql intl zip bcmath pcntl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG UID=1000
RUN useradd -m -u ${UID} app
USER app
WORKDIR /var/www/html
