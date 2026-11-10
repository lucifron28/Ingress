#!/usr/bin/env bash
set -Eeuo pipefail

api_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_dir="$(mktemp -d "$api_root/.node-docker-test.XXXXXX")"
temporary_root="$(cd "${TMPDIR:-/tmp}" && pwd)"
secrets_dir="$(mktemp -d "$temporary_root/ingress-node-docker-test-secrets.XXXXXX")"
project="ingress-node-ci-$(date +%s)-$$"
env_file="$test_dir/node.env"
base64_sync_key=''
app_key=''
database_password=''

compose() {
    docker compose --project-name "$project" --env-file "$env_file" --file "$api_root/compose.node.yaml" "$@"
}

on_error() {
    status=$?
    printf 'Event Node Docker integration failed near shell line %s (exit %s).\n' "${BASH_LINENO[0]:-unknown}" "$status" >&2
    exit "$status"
}
trap on_error ERR

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        compose logs --no-color 2>&1 | tail -n 25 >&2 || true
    fi

    compose down --volumes --remove-orphans --timeout 10 >/dev/null 2>&1 || true

    case "$test_dir" in
        "$api_root"/.node-docker-test.*) rm -rf -- "$test_dir" ;;
        *) printf 'Refusing to remove unexpected test directory.\n' >&2; exit 1 ;;
    esac

    case "$secrets_dir" in
        "$temporary_root"/ingress-node-docker-test-secrets.*) rm -rf -- "$secrets_dir" ;;
        *) printf 'Refusing to remove unexpected test secrets directory.\n' >&2; exit 1 ;;
    esac

    exit "$status"
}
trap cleanup EXIT

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
database_password="$(openssl rand -hex 32)"
base64_sync_key="$(openssl rand -base64 32 | tr -d '\n')"
printf '%s\n' "$app_key" > "$secrets_dir/app_key"
printf '%s\n' "$database_password" > "$secrets_dir/db_password"
printf '%s\n' "$base64_sync_key" > "$secrets_dir/node_sync_key"

openssl genpkey -algorithm ED25519 -out "$test_dir/authority-private.pem" >/dev/null 2>&1
authority_public_key="$(openssl pkey -in "$test_dir/authority-private.pem" -pubout -outform DER 2>/dev/null | tail -c 32 | base64 | tr -d '\n')"
rm -f -- "$test_dir/authority-private.pem"

openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout "$secrets_dir/node_tls_private_key" \
    -out "$secrets_dir/node_tls_certificate" \
    -subj '/CN=node.test' -addext 'subjectAltName=DNS:node.test' -days 1 >/dev/null 2>&1
# These are synthetic, short-lived test secrets. A Linux bind-mounted Compose
# secret keeps the source file's owner and mode, so make the fixture readable by
# the image's non-root UID 10001 while keeping it read-only.
chmod 0444 "$secrets_dir"/*

https_port=18443
operator_port=18444
egress_subnet=172.31.250.0/28
egress_gateway=172.31.250.1
cat > "$env_file" <<EOF
APP_URL=https://node.test
INGRESS_NODE_ID=venue-node-test
INGRESS_CLOUD_SYNC_URL=https://ingress-pp2u.onrender.com/api/v1
INGRESS_AUTHORITY_PUBLIC_KEY=$authority_public_key
INGRESS_NODE_BIND_ADDRESS=127.0.0.1
INGRESS_NODE_HTTPS_PORT=$https_port
INGRESS_OPERATOR_PORT=$operator_port
INGRESS_EGRESS_SUBNET=$egress_subnet
INGRESS_EGRESS_GATEWAY=$egress_gateway
NODE_SECRETS_DIR=$secrets_dir
DB_DATABASE=ingress_node
DB_USERNAME=ingress_node
EOF

compose config --quiet
compose build ingress-node

docker run --rm --entrypoint php ingress-node:compose -r '
    $required = ["mbstring", "pdo_pgsql", "pcntl", "sodium", "xmlwriter"];
    $missing = array_diff($required, get_loaded_extensions());
    if ($missing || ! extension_loaded("Zend OPcache")) {
        fwrite(STDERR, "A required PHP extension is missing.\n");
        exit(1);
    }
    fwrite(STDOUT, "Required PHP extensions are loaded.\n");
'
printf 'Image build and extension checks passed.\n'

compose up --detach ingress-node ingress-scheduler >/dev/null

node_url="https://node.test:$https_port"
operator_url="https://node.test:$operator_port"
status=''
for attempt in $(seq 1 90); do
    status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
        --cacert "$secrets_dir/node_tls_certificate" \
        --resolve "node.test:$https_port:127.0.0.1" \
        "$node_url/up?ticket=synthetic-ticket-secret" || true)"
    if [ "$status" = '200' ]; then
        break
    fi
    sleep 1
done
if [ "$status" != '200' ]; then
    printf 'Public HTTPS health endpoint returned %s.\n' "$status" >&2
    exit 1
fi

migration_container="$(compose ps --all --quiet ingress-migrate)"
if [ -z "$migration_container" ] || [ "$(docker inspect --format '{{.State.ExitCode}}' "$migration_container")" != '0' ]; then
    printf 'The guarded migration service did not complete successfully.\n' >&2
    exit 1
fi
printf 'Database initialization and guarded migrations passed.\n'

node_id="$(compose ps --all --quiet ingress-node)"
db_id="$(compose ps --all --quiet ingress-db)"
scheduler_id="$(compose ps --all --quiet ingress-scheduler)"
test -n "$node_id" && test -n "$db_id" && test -n "$scheduler_id"
test "$(docker inspect --format '{{.Config.User}}' "$node_id")" = '10001:10001'
test "$(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$node_id")" = 'true'
node_security="$(docker inspect --format '{{json .HostConfig.CapDrop}} {{json .HostConfig.SecurityOpt}}' "$node_id")"
case "$node_security" in *'"ALL"'*'no-new-privileges:true'*) ;; *) printf 'Node container is missing its privilege restrictions.\n' >&2; exit 1 ;; esac
if [ -n "$(docker port "$db_id")" ]; then
    printf 'PostgreSQL unexpectedly publishes a host port.\n' >&2
    exit 1
fi
printf 'Non-root and container privilege checks passed.\n'

operator_mapping="$(docker port "$node_id" 8444/tcp)"
if [ "$operator_mapping" != "127.0.0.1:$operator_port" ]; then
    printf 'Operator port was published on an unexpected address: %s\n' "$operator_mapping" >&2
    exit 1
fi
operator_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cacert "$secrets_dir/node_tls_certificate" \
    --resolve "node.test:$operator_port:127.0.0.1" \
    "$operator_url/node/operator/login")"
if [ "$operator_status" != '200' ]; then
    printf 'Loopback operator login endpoint returned %s.\n' "$operator_status" >&2
    exit 1
fi

scanner_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cacert "$secrets_dir/node_tls_certificate" \
    --resolve "node.test:$https_port:127.0.0.1" \
    --header 'X-Forwarded-For: 127.0.0.1' \
    "$node_url/node/operator/login")"
test "$scanner_status" = '404'

dashboard_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --cacert "$secrets_dir/node_tls_certificate" \
    --resolve "node.test:$operator_port:127.0.0.1" \
    "$operator_url/node/operator")"
test "$dashboard_status" = '302'
printf 'HTTPS, scanner denial, and loopback operator checks passed.\n'

if curl --silent --fail --max-time 3 --output /dev/null "http://127.0.0.1:$https_port/up"; then
    printf 'The public listener accepted plain HTTP.\n' >&2
    exit 1
fi
if curl --silent --insecure --fail --max-time 3 --output /dev/null \
    --resolve "unknown.test:$https_port:127.0.0.1" "https://unknown.test:$https_port/up"; then
    printf 'The public listener accepted an unknown TLS name.\n' >&2
    exit 1
fi

database_identity="$(docker exec "$db_id" psql -U ingress_node -d ingress_node -At -F '|' \
    -c "select current_setting('server_version_num'), mode, identity from runtime_identity where id = 1")"
case "$database_identity" in 18*'|node|venue-node-test') ;; *) printf 'Unexpected PostgreSQL version or runtime identity.\n' >&2; exit 1 ;; esac
database_stores="$(docker exec "$db_id" psql -U ingress_node -d ingress_node -At -F '|' \
    -c "select to_regclass('public.sessions') is not null, to_regclass('public.cache') is not null, to_regclass('public.cache_locks') is not null")"
test "$database_stores" = 't|t|t'
printf 'PostgreSQL 18 identity and database-backed stores passed.\n'

if compose exec --no-TTY ingress-scheduler php -r '
    $ip = gethostbyname("ingress-node");
    $request = curl_init("https://node.test:8444/node/operator/login");
    curl_setopt_array($request, [
        CURLOPT_RESOLVE => ["node.test:8444:".$ip],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    exit($status === 403 ? 0 : 1);
'; then
    :
else
    printf 'The scheduler can reach the operator-only listener.\n' >&2
    exit 1
fi

if compose run --rm --no-deps --env INGRESS_NODE_ID=venue-node-other ingress-migrate >/dev/null 2>&1; then
    printf 'A different node identity was incorrectly allowed to migrate this database.\n' >&2
    exit 1
fi
database_identity_after_rejection="$(docker exec "$db_id" psql -U ingress_node -d ingress_node -At -F '|' \
    -c "select mode, identity from runtime_identity where id = 1")"
test "$database_identity_after_rejection" = 'node|venue-node-test'
printf 'Mismatched Node identity was rejected without changing the database.\n'

docker exec "$db_id" psql -U ingress_node -d ingress_node -v ON_ERROR_STOP=1 \
    -c 'create table node_docker_persistence_probe (id integer primary key, value text not null); insert into node_docker_persistence_probe values (1, '\''persisted'\'');' >/dev/null
compose up --detach --force-recreate ingress-db >/dev/null
db_id="$(compose ps --quiet ingress-db)"
for attempt in $(seq 1 30); do
    if docker exec "$db_id" pg_isready -U ingress_node -d ingress_node >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
persistence_value="$(docker exec "$db_id" psql -U ingress_node -d ingress_node -At \
    -c 'select value from node_docker_persistence_probe where id = 1')"
test "$persistence_value" = 'persisted'
printf 'PostgreSQL data survived container recreation.\n'

container_environment="$(docker inspect --format '{{json .Config.Env}}' "$node_id")"
for secret_value in "$app_key" "$database_password" "$base64_sync_key"; do
    if printf '%s' "$container_environment" | grep --fixed-strings --quiet "$secret_value"; then
        printf 'A secret value is present in the node container configuration.\n' >&2
        exit 1
    fi
done
container_logs="$(compose logs --no-color)"
for secret_value in "$app_key" "$database_password" "$base64_sync_key" synthetic-ticket-secret; do
    if printf '%s' "$container_logs" | grep --fixed-strings --quiet "$secret_value"; then
        printf 'A secret or ticket test value is present in container logs.\n' >&2
        exit 1
    fi
done

printf 'Event Node Docker integration passed: migrations, identity guard, HTTPS listeners, operator isolation, database stores, restart persistence, and secret/log checks.\n'
