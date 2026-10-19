FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json vite.network.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
RUN npm run build

FROM php:8.3-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install -j2 intl zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
ENV APP_NAME="Filament Network Studio" \
    APP_ENV=local \
    APP_DEBUG=false \
    APP_URL=http://localhost:8000 \
    APP_LOCALE=fa \
    APP_FALLBACK_LOCALE=en \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    MAIL_MAILER=log \
    SEED_DEMO=true \
    COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public \
    && composer dump-autoload --no-dev --optimize \
    && composer check-platform-reqs --no-dev \
    && rm -rf /root/.composer/cache
COPY --from=assets /build/public/network-editor ./public/network-editor
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/studio-entrypoint
RUN chmod +x /usr/local/bin/studio-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

VOLUME ["/data"]
EXPOSE 80
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=6 CMD ["php", "/var/www/html/docker/healthcheck.php"]
ENTRYPOINT ["studio-entrypoint"]
CMD ["apache2-foreground"]
