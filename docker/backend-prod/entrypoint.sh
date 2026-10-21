#!/bin/bash
# Production entrypoint for the Waselna Delivery_API containers on ECS Fargate.
#
# Configuration arrives entirely through environment variables injected by the
# ECS task definition (plain vars) and AWS Secrets Manager (APP_KEY, DB and
# Redis credentials). Nothing is read from a committed .env file.
#
# The same image runs three roles, chosen by the container command:
#   serve-http                      -> HTTP API (php-fpm + nginx)
#   php artisan queue:work ...      -> queue worker
#   php artisan reverb:start ...    -> realtime gateway
#
# Database migrations are NOT run automatically on every container start (that
# would race across multiple tasks). The deploy pipeline runs them once as a
# dedicated one-off ECS task with RUN_MIGRATIONS=true.
set -e

cd /var/www/html

# Cache framework config and routes for production. Rebuilt on every container
# start so a new image/secret rotation is always reflected.
php artisan config:cache || true
php artisan route:cache || true
php artisan event:cache || true

# One-off migration path, used by the deploy pipeline's migration task only.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "entrypoint: running database migrations"
    php artisan migrate --force
    if [ "${RUN_SEED:-false}" = "true" ]; then
        echo "entrypoint: seeding demonstration data"
        php artisan db:seed --force || echo "entrypoint: seed skipped"
    fi
    echo "entrypoint: migrations complete"
    exit 0
fi

exec "$@"