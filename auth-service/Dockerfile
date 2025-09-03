FROM php:8.3-cli-alpine

# System & PHP extensions for Postgres + Redis extension
RUN apk add --no-cache git unzip libzip-dev oniguruma-dev postgresql-dev $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && docker-php-ext-install pdo_pgsql mbstring bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-interaction --prefer-dist --no-progress || true

EXPOSE 9001
CMD php artisan serve --host=0.0.0.0 --port=9001
