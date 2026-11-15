FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# The same, plus PHPUnit
FROM vendor AS vendor-dev
RUN composer install --no-interaction --prefer-dist --no-scripts

FROM php:8.4-apache AS base

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite remoteip headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    # Hosts tell the container which port to use through $PORT
    && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

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
CMD ["sh", "-c", "php database/migrate.php & exec apache2-foreground"]
