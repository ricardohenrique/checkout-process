# syntax=docker/dockerfile:1.6

# ---------------------------------------------------------------------------
# Stage 1: materialise a fresh Laravel 11 scaffold (artisan, config/, public/,
# storage/, bootstrap/cache, etc.) The assessment repo ships only the
# application slice, so we generate the framework boilerplate here.
# ---------------------------------------------------------------------------
FROM composer:2 AS scaffold

RUN composer create-project laravel/laravel /scaffold "12.*" \
        --prefer-dist \
        --no-interaction \
        --no-scripts \
        --no-progress \
    && rm -rf /scaffold/.git /scaffold/.github

# ---------------------------------------------------------------------------
# Stage 2: runtime. PHP 8.5-cli paired with the Laravel 12 scaffold above.
# ---------------------------------------------------------------------------
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Bring in the Laravel scaffold first so files like artisan / config / public
# exist before the overlay drops in.
COPY --from=scaffold /scaffold/ /app/

# Overlay the assessment slice on top of the scaffold.
COPY composer.json                 /app/composer.json
COPY phpunit.xml                   /app/phpunit.xml
COPY .env.example                  /app/.env.example
COPY app/                          /app/app/
COPY bootstrap/app.php             /app/bootstrap/app.php
COPY database/migrations/          /app/database/migrations/
COPY database/seeders/             /app/database/seeders/
COPY routes/                       /app/routes/
COPY tests/                        /app/tests/
COPY resources/                    /app/resources/

# Drop the scaffold's lock file: it was generated against the composer:2 image's
# PHP (8.4+), which pulls Symfony 8 packages that don't run on this runtime's
# PHP 8.3. Resolving fresh from composer.json picks Laravel 11-compatible deps.
RUN rm -f composer.lock \
    && composer update \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress

# Bootstrap the environment: .env, app key, sqlite db, migrations, seed.
RUN cp .env.example .env \
    && php artisan key:generate --force \
    && mkdir -p database \
    && touch database/database.sqlite \
    && php artisan migrate --force \
    && php artisan db:seed --class=ProductSeeder --force

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
