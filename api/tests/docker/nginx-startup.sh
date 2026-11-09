#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then
    echo "Nginx startup regression test must run as the image's non-root user." >&2
    exit 1
fi

mkdir -p /tmp/entrypoint-test-bin
cat > /tmp/entrypoint-test-bin/php <<'PHP_MOCK'
#!/bin/sh
if [ "${1:-}" = "artisan" ]; then
    exit 0
fi
exec /usr/local/bin/php "$@"
PHP_MOCK
chmod 0755 /tmp/entrypoint-test-bin/php

rm -rf /tmp/nginx /tmp/nginx.conf
PATH="/tmp/entrypoint-test-bin:$PATH" /var/www/html/docker/entrypoint.sh &
entrypoint_pid=$!

cleanup() {
    kill -TERM "$entrypoint_pid" 2>/dev/null || true
    wait "$entrypoint_pid" 2>/dev/null || true
}
trap cleanup EXIT HUP INT TERM

attempt=0
while [ "$attempt" -lt 15 ]; do
    if ! kill -0 "$entrypoint_pid" 2>/dev/null; then
        echo "Render entrypoint exited before Nginx became ready." >&2
        exit 1
    fi

    directories_ready=true
    for directory in \
        /tmp/nginx/client_body \
        /tmp/nginx/fastcgi \
        /tmp/nginx/proxy \
        /tmp/nginx/uwsgi \
        /tmp/nginx/scgi; do
        if [ ! -d "$directory" ] || [ ! -w "$directory" ]; then
            directories_ready=false
            break
        fi
    done

    if [ "$directories_ready" = true ] && php -r "exit(@file_get_contents(\"http://127.0.0.1:\".getenv(\"PORT\").\"/build/manifest.json\") === false ? 1 : 0);"; then
        echo "Nginx recreated all temporary directories and served the build manifest on PORT ${PORT}."
        exit 0
    fi

    attempt=$((attempt + 1))
    sleep 1
done

echo "Nginx did not serve the build manifest on PORT ${PORT}." >&2
exit 1
