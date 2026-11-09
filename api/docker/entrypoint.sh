#!/bin/sh
set -eu

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*)
        echo "Render startup failed: PORT must be an integer." >&2
        exit 64
        ;;
esac

if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo "Render startup failed: PORT must be between 1024 and 65535." >&2
    exit 64
fi

if ! envsubst '${PORT}' < /var/www/html/docker/nginx.conf.template > /tmp/nginx.conf; then
    echo "Render startup failed: could not configure the HTTP server." >&2
    exit 1
fi

if ! php artisan ingress:render-start --no-interaction; then
    echo "Render startup aborted before the HTTP server started." >&2
    exit 1
fi

if ! php artisan config:cache --no-interaction; then
    echo "Render startup failed while caching Laravel configuration." >&2
    exit 1
fi

if ! php artisan route:cache --no-interaction; then
    echo "Render startup failed while caching Laravel routes." >&2
    exit 1
fi

if ! php artisan view:cache --no-interaction; then
    echo "Render startup failed while caching Blade views." >&2
    exit 1
fi

exec supervisord -n -c /var/www/html/docker/supervisord.conf
