<?php

namespace App\Support;

class NodeDockerDeploymentConfiguration
{
    /** @return list<string> */
    public function errors(): array
    {
        return self::validate([
            'environment' => app()->environment(),
            'app' => config('app'),
            'ingress' => config('ingress'),
            'database' => config('database'),
            'session' => config('session'),
            'cache' => config('cache'),
            'queue' => config('queue'),
            'logging' => config('logging'),
        ]);
    }

    /** @param array<string, mixed> $configuration
     * @return list<string>
     */
    public static function validate(array $configuration): array
    {
        $errors = [];
        $environment = $configuration['environment'] ?? null;
        $app = is_array($configuration['app'] ?? null) ? $configuration['app'] : [];
        $ingress = is_array($configuration['ingress'] ?? null) ? $configuration['ingress'] : [];
        $database = is_array($configuration['database'] ?? null) ? $configuration['database'] : [];

        if ($environment !== 'production') {
            $errors[] = 'APP_ENV must be production.';
        }

        if (($ingress['mode'] ?? null) !== 'node') {
            $errors[] = 'INGRESS_RUNTIME must be node.';
        }

        if (($app['debug'] ?? true) !== false) {
            $errors[] = 'APP_DEBUG must be false.';
        }

        if (($app['demo_mode'] ?? true) !== false) {
            $errors[] = 'INGRESS_DEMO_MODE must be false.';
        }

        if (! self::hasValidApplicationKey($app['key'] ?? null)) {
            $errors[] = 'APP_KEY must be a valid 32-byte Laravel key.';
        }

        $lanDemo = ($ingress['lan_demo'] ?? false) === true;
        $validNodeOrigin = $lanDemo
            ? self::isLanDemoOrigin($app['url'] ?? null, $ingress['node_bind_address'] ?? null)
            : self::isHttpsOrigin($app['url'] ?? null);
        if (! $validNodeOrigin) {
            $errors[] = $lanDemo
                ? 'APP_URL must be an HTTP origin matching the selected private LAN address.'
                : 'APP_URL must be an HTTPS node origin.';
        }

        if (! is_string($ingress['node_id'] ?? null)
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $ingress['node_id'])) {
            $errors[] = 'INGRESS_NODE_ID must be a valid node ID.';
        }

        if (! self::isBase64Key($ingress['node_sync_key'] ?? null, SODIUM_CRYPTO_AUTH_KEYBYTES)) {
            $errors[] = 'INGRESS_NODE_SYNC_KEY must be a 32-byte base64 key.';
        }

        if (! self::isHttpsApiUrl($ingress['cloud_sync_url'] ?? null)) {
            $errors[] = 'INGRESS_CLOUD_SYNC_URL must be the HTTPS cloud API origin ending in /api/v1.';
        }

        if (is_string($ingress['authority_private_key'] ?? null)
            && trim($ingress['authority_private_key']) !== '') {
            $errors[] = 'INGRESS_AUTHORITY_PRIVATE_KEY must not be configured on an Event Node.';
        }

        if (! self::isBase64Key($ingress['authority_public_key'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)) {
            $errors[] = 'INGRESS_AUTHORITY_PUBLIC_KEY must be a 32-byte base64 Ed25519 public key.';
        }

        if (($ingress['trusted_proxies'] ?? null) !== []) {
            $errors[] = 'TRUSTED_PROXIES must be empty for the direct HTTPS node listener.';
        }

        $operatorGateway = $ingress['operator_gateway'] ?? null;
        $operatorCidrs = $ingress['operator_allowed_cidrs'] ?? null;
        if (! is_string($operatorGateway)
            || filter_var($operatorGateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $operatorCidrs !== [$operatorGateway.'/32']) {
            $errors[] = 'Operator access must allow only the configured Docker bridge gateway.';
        }

        $connections = $database['connections'] ?? [];
        $pgsql = is_array($connections) ? ($connections['pgsql'] ?? []) : [];
        if (($database['default'] ?? null) !== 'pgsql'
            || ! is_array($pgsql)
            || ($pgsql['driver'] ?? null) !== 'pgsql'
            || ($pgsql['host'] ?? null) !== 'ingress-db'
            || trim((string) ($pgsql['database'] ?? '')) === ''
            || trim((string) ($pgsql['username'] ?? '')) === ''
            || trim((string) ($pgsql['password'] ?? '')) === '') {
            $errors[] = 'DB_CONNECTION must use the private ingress-db PostgreSQL service and credentials.';
        }

        if (is_array($pgsql) && is_string($pgsql['url'] ?? null) && trim($pgsql['url']) !== '') {
            $errors[] = 'DB_URL must be empty so the node uses only its private PostgreSQL service.';
        }

        $session = is_array($configuration['session'] ?? null) ? $configuration['session'] : [];
        if (($session['driver'] ?? null) !== 'database'
            || ($session['secure'] ?? false) !== ! $lanDemo
            || ($session['encrypt'] ?? false) !== true
            || ($session['http_only'] ?? false) !== true
            || ($session['same_site'] ?? null) !== 'strict') {
            $errors[] = 'Sessions must use encrypted PostgreSQL storage and secure cookies.';
        }

        $cache = is_array($configuration['cache'] ?? null) ? $configuration['cache'] : [];
        if (($cache['default'] ?? null) !== 'database') {
            $errors[] = 'CACHE_STORE must use the database cache store.';
        }

        $queue = is_array($configuration['queue'] ?? null) ? $configuration['queue'] : [];
        if (($queue['default'] ?? null) !== 'sync') {
            $errors[] = 'QUEUE_CONNECTION must use the synchronous queue.';
        }

        if (($app['maintenance']['driver'] ?? null) !== 'cache'
            || ($app['maintenance']['store'] ?? null) !== 'database') {
            $errors[] = 'Maintenance mode must use the database cache store.';
        }

        $logging = is_array($configuration['logging'] ?? null) ? $configuration['logging'] : [];
        if (($logging['default'] ?? null) !== 'stderr') {
            $errors[] = 'LOG_CHANNEL must write to stderr.';
        }

        return array_values(array_unique($errors));
    }

    private static function isHttpsOrigin(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && self::isSafeServerName($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    private static function isLanDemoOrigin(mixed $url, mixed $bindAddress): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($bindAddress) || ! self::isPrivateIpv4($bindAddress)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
            || ($parts['host'] ?? null) !== $bindAddress
            || ! isset($parts['port'])
            || $parts['port'] < 1 || $parts['port'] > 65535
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }

        return true;
    }

    private static function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $octets = array_map('intval', explode('.', $address));

        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }

    private static function isSafeServerName(mixed $host): bool
    {
        if (! is_string($host) || $host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        return preg_match(
            '/\A[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/',
            $host,
        ) === 1;
    }

    private static function isHttpsApiUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/api/v1';
    }

    private static function hasValidApplicationKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            return self::isBase64Key(substr($key, 7), 32);
        }

        return strlen($key) === 32;
    }

    private static function isBase64Key(mixed $key, int $length): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $decoded = base64_decode($key, true);

        return is_string($decoded)
            && strlen($decoded) === $length
            && hash_equals(base64_encode($decoded), $key);
    }
}
