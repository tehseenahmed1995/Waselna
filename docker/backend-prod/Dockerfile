# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# Waselna Delivery_API — PRODUCTION image.
#
# Unlike the Local_Dev_Environment image (docker/backend/Dockerfile), this image
# BAKES the application source and vendored Composer dependencies in, so it runs
# standalone on ECS Fargate with no bind mount. One image serves all three
# backend roles (http api, queue worker, reverb) selected by the container CMD.
# -----------------------------------------------------------------------------

# ---- Stage 1: composer dependencies (no dev) --------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ---- Stage 2: runtime -------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-dev \
        libpq \
        libzip-dev \
        nginx \
        postgresql-client \
        bash \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        linux-headers \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Production OPcache: validate_timestamps=0 is safe because the code is baked in
# and never changes within a running container.
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "realpath_cache_size=4096K"; \
        echo "realpath_cache_ttl=600"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
        echo "[www]"; \
        echo "pm = dynamic"; \
        echo "pm.max_children = 40"; \
        echo "pm.start_servers = 8"; \
        echo "pm.min_spare_servers = 4"; \
        echo "pm.max_spare_servers = 16"; \
        echo "clear_env = no"; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/backend/nginx.conf /etc/nginx/nginx.conf
COPY docker/backend/serve-http.sh /usr/local/bin/serve-http
COPY docker/backend-prod/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html

# Application source + pre-installed vendor directory.
COPY backend/ /var/www/html/
COPY --from=vendor /app/vendor /var/www/html/vendor

# Finish the autoloader now that the full source is present, and cache
# framework config/routes for production performance.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && sed -i "s/\r$//" /usr/local/bin/entrypoint /usr/local/bin/serve-http \
    && chmod +x /usr/local/bin/entrypoint /usr/local/bin/serve-http \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV APP_ENV=production
EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["serve-http"]