FROM composer:2.10.3 AS composer
FROM php:8.4.26-fpm-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql pcntl bcmath zip opcache \
    && pecl install redis-6.3.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
RUN mkdir -p /var/www/.composer && chown www-data:www-data /var/www/.composer
ENV COMPOSER_HOME=/var/www/.composer
USER www-data
CMD ["php-fpm", "-F"]

FROM runtime AS dependencies
COPY --chown=www-data:www-data . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM node:24.14.0-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run typecheck && npm run build

FROM runtime AS production
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=frontend --chown=www-data:www-data /build/public/build /var/www/html/public/build

FROM nginx:1.30.5 AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public
