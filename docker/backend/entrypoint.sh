#!/bin/sh
# Entrypoint of the three Delivery_API containers.
#
# A clean checkout has no vendor directory and no environment file, so the
# entrypoint installs dependencies and materialises `.env` from the committed
# example before handing control to the service command. That is what makes the
# single documented start command sufficient (Requirement 24.2).
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
    echo "entrypoint: created .env from .env.example"
fi

if [ ! -f vendor/autoload.php ]; then
    echo "entrypoint: installing composer dependencies"
    composer install --no-interaction --prefer-dist
fi

# Generate an application key when the committed placeholder is still in place.
if grep -q "APP_KEY=base64:AAAA" .env 2>/dev/null; then
    php artisan key:generate --force || true
fi

# The HTTP service migrates and seeds on boot so the single documented start
# command yields a ready Local_Dev_Environment. The worker and reverb services
# skip this (they share the image but pass a different command).
case "$*" in
    *serve-http*|*"artisan serve"*)
        echo "entrypoint: waiting for database then migrating"
        php artisan migrate --force --seed || echo "entrypoint: migrate deferred"
        ;;
esac

exec "$@"
