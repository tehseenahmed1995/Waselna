#!/bin/sh
# Starts the Delivery_API HTTP service: php-fpm (worker pool) plus nginx as the
# front, so concurrent requests from the Web_Client are served in parallel.
set -e

# php-fpm in the background; nginx in the foreground so the container's lifetime
# follows nginx. If either exits, the container stops and Compose restarts it.
php-fpm --daemonize
exec nginx -g 'daemon off;'
