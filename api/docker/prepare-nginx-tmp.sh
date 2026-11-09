#!/bin/sh
set -eu

if ! mkdir -p \
    /tmp/nginx/client_body \
    /tmp/nginx/fastcgi \
    /tmp/nginx/proxy \
    /tmp/nginx/uwsgi \
    /tmp/nginx/scgi; then
    echo "Render startup failed: could not prepare HTTP server temporary directories." >&2
    exit 1
fi
