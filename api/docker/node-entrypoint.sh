#!/bin/sh
set -eu

fail() {
    printf 'Event Node startup failed: %s\n' "$1" >&2
    exit "${2:-1}"
}

read_secret() {
    variable="$1"
    secret_path="$2"

    if [ ! -r "$secret_path" ]; then
        fail "required Docker secret is unavailable ($variable)." 64
    fi

    secret_value=$(cat "$secret_path") || fail "required Docker secret could not be read ($variable)." 64
    if [ -z "$secret_value" ]; then
        fail "required Docker secret is empty ($variable)." 64
    fi

    case "$variable" in
        APP_KEY) APP_KEY=$secret_value ;;
        DB_PASSWORD) DB_PASSWORD=$secret_value ;;
        INGRESS_NODE_SYNC_KEY) INGRESS_NODE_SYNC_KEY=$secret_value ;;
        *) fail 'internal secret configuration is invalid.' 64 ;;
    esac
    export "$variable"
}

role="${1:-serve}"
if [ "$#" -gt 0 ]; then
    shift
fi

case "$role" in
    migrate|serve|scheduler|cli) ;;
    *) fail 'service role must be migrate, serve, scheduler, or cli.' 64 ;;
esac

read_secret APP_KEY /run/secrets/app_key
read_secret DB_PASSWORD /run/secrets/db_password
read_secret INGRESS_NODE_SYNC_KEY /run/secrets/node_sync_key

mkdir -p \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/bootstrap/cache

case "$role" in
    migrate)
        exec php artisan ingress:node-migrate --no-interaction
        ;;
    scheduler)
        php artisan ingress:node-start --no-interaction || fail 'runtime verification failed before the scheduler started.'
        exec php artisan schedule:work --no-interaction
        ;;
    cli)
        php artisan ingress:node-start --no-interaction || fail 'runtime verification failed before the maintenance command started.'
        if [ "$#" -eq 0 ]; then
            fail 'maintenance command is required.' 64
        fi
        exec php artisan "$@"
        ;;
    serve)
        if ! php -r '$gateway = getenv("INGRESS_OPERATOR_GATEWAY"); $allowed = explode(",", (string) getenv("INGRESS_OPERATOR_ALLOWED_CIDRS")); exit(filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $allowed === [$gateway."/32"] ? 0 : 1);'; then
            fail 'operator access must be limited to the configured Docker bridge gateway.' 64
        fi

        lan_demo=${INGRESS_NODE_LAN_DEMO:-false}
        case "$lan_demo" in
            true)
                nginx_template=/etc/nginx/node-lan-demo.conf.template
                ;;
            false)
                if [ ! -r /run/secrets/node_tls_certificate ] || [ ! -s /run/secrets/node_tls_certificate ]; then
                    fail 'TLS certificate secret is missing or empty.' 64
                fi
                if [ ! -r /run/secrets/node_tls_private_key ] || [ ! -s /run/secrets/node_tls_private_key ]; then
                    fail 'TLS private-key secret is missing or empty.' 64
                fi
                nginx_template=/etc/nginx/node.conf.template
                ;;
            *)
                fail 'INGRESS_NODE_LAN_DEMO must be true or false.' 64
                ;;
        esac

        php artisan ingress:node-start --no-interaction || fail 'runtime verification failed before the web server started.'

        app_host=$(php -r '$host = parse_url((string) getenv("APP_URL"), PHP_URL_HOST); if (! is_string($host)) { exit(1); } echo $host;') \
            || fail 'APP_URL does not contain a valid host name.' 64
        APP_HOST=$app_host
        export APP_HOST

        mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi
        if ! envsubst '${APP_HOST} ${INGRESS_OPERATOR_GATEWAY}' \
            < "$nginx_template" > /tmp/nginx.conf; then
            fail 'could not configure the Event Node listeners.'
        fi
        if ! nginx -e stderr -t -c /tmp/nginx.conf; then
            fail 'Nginx configuration validation failed.'
        fi
        exec supervisord -n -c /etc/supervisord-node.conf
        ;;
esac
