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
    && docker-php-ext-install pdo_mysql \
    # Debian's Apache already uses the event MPM
    && a2enmod proxy_fcgi rewrite remoteip headers \
    && a2dissite 000-default \
    && : > /etc/apache2/ports.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache.conf /etc/apache2/sites-enabled/mapchat.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-mapchat.conf

ENV PORT=8080
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

# Bring an older database up to date in the background, so Apache starts
# serving right away after the free plan wakes the service
# PHP-FPM in the background, Apache in front as the container's main process.
# A pid file left by the last run (a restarted container) would stop Apache
# starting, if its old pid now belongs to another process
CMD ["sh", "-c", "php database/migrate.php & php-fpm -D && rm -f /var/run/apache2/apache2.pid && exec apache2ctl -D FOREGROUND"]
