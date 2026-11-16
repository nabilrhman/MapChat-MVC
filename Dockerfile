FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# The same, plus PHPUnit
FROM vendor AS vendor-dev
RUN composer install --no-interaction --prefer-dist --no-scripts

# Apache's event MPM in front of PHP-FPM (see docker/apache.conf)
FROM php:8.4-fpm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends apache2 \
    && rm -rf /var/lib/apt/lists/* \
    # pcntl lets the chat socket and worker stop cleanly on SIGTERM
    && docker-php-ext-install pdo_mysql pcntl \
    # Debian's Apache already uses the event MPM; proxy_wstunnel passes /ws to the chat socket
    && a2enmod proxy_fcgi proxy_wstunnel rewrite remoteip headers \
    && a2dissite 000-default \
    && : > /etc/apache2/ports.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache.conf /etc/apache2/sites-enabled/mapchat.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-mapchat.conf

ENV PORT=8080
# Where the chat socket listens, inside the container (Config::chatSocketPort)
ENV CHAT_SOCKET_PORT=8090
WORKDIR /var/www/html

# The tests, with PHPUnit: `docker compose run --rm test`
FROM base AS test
COPY --from=vendor-dev /app/vendor ./vendor
COPY . .
CMD ["vendor/bin/phpunit"]

# The app. Last, so it is what a plain `docker build` makes
FROM base
COPY --from=vendor /app/vendor ./vendor
COPY . .
# A new id for each build, so open chat pages can tell a new version is live
RUN date +%s > build-id
RUN chown -R www-data:www-data logs

EXPOSE 8080

# PHP-FPM, Apache, the chat socket and the chat worker (see docker/start.sh).
# The php-fpm image stops with SIGQUIT; start.sh stops everything on SIGTERM
STOPSIGNAL SIGTERM
CMD ["sh", "docker/start.sh"]
