# Delivery_API development image, shared by the `backend`, `queue-worker`, and
# `reverb` services of the Local_Dev_Environment (Requirement 24.1).
#
# The image carries the runtime: PHP 8.4 (FPM) with the PostgreSQL and Redis
# extensions, nginx, and Composer. The HTTP service runs php-fpm behind nginx so
# concurrent requests are served by a pool of workers (the single-process
# `artisan serve` serialised every request and stalled the SPA). The
# queue-worker and reverb services share this image but run CLI commands.
# Application source arrives through the bind mount declared in
# docker-compose.yml, so a source change needs no rebuild (Requirement 24.3).

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        git \
        icu-dev \
        libpq \
        libzip-dev \
        nginx \
        postgresql-client \
        unzip \
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

# OPcache keeps compiled bytecode in memory so each request does not recompile
# the framework from the bind-mounted source — the dominant per-request cost on
# a Windows/WSL2 bind mount. validate_timestamps=0 means a backend source edit
# needs `docker compose restart backend` to take effect.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# A php-fpm pool large enough to serve the SPA's parallel calls without queueing.
RUN { \
        echo '[www]'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 20'; \
        echo 'pm.start_servers = 6'; \
        echo 'pm.min_spare_servers = 4'; \
        echo 'pm.max_spare_servers = 10'; \
        echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

COPY docker/backend/nginx.conf /etc/nginx/nginx.conf
COPY docker/backend/serve-http.sh /usr/local/bin/serve-http

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint

# Strip any CR characters (the scripts may be authored on Windows with CRLF line
# endings, which would make the shebang `#!/bin/sh\r` and the command name
# unresolvable) and make them executable.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint /usr/local/bin/serve-http \
    && chmod +x /usr/local/bin/entrypoint /usr/local/bin/serve-http

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["serve-http"]
