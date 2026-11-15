<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

final class RenderDeploymentConfiguration
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
            'services' => config('services'),
            'logging' => config('logging'),
        ]);
    }

    /** @return list<string> */
    public static function validate(array $config): array
    {
        $errors = self::runtimeErrors(
            $config['app'] ?? [],
            $config['ingress'] ?? [],
            (string) ($config['environment'] ?? ''),
        );
        $errors = [...$errors, ...self::authorityKeyErrors($config['ingress'] ?? [])];

        $errors = [...$errors, ...self::databaseErrors($config['database'] ?? [])];

        $session = $config['session'] ?? [];
        if (($session['driver'] ?? null) !== 'database'
            || ($session['secure'] ?? false) !== true
            || ($session['encrypt'] ?? false) !== true) {
            $errors[] = 'Sessions must use encrypted PostgreSQL storage and secure cookies.';
        }

        if (($config['cache']['default'] ?? null) !== 'database') {
            $errors[] = 'CACHE_STORE must use the database cache store.';
        }

        if (($config['queue']['default'] ?? null) !== 'sync') {
            $errors[] = 'QUEUE_CONNECTION must be sync on Render Free.';
        }

        if (($config['app']['maintenance']['driver'] ?? null) !== 'cache'
            || ($config['app']['maintenance']['store'] ?? null) !== 'database') {
            $errors[] = 'Maintenance mode must use the database cache store.';
        }

        if (($config['logging']['default'] ?? null) !== 'stderr') {
            $errors[] = 'LOG_CHANNEL must write to stderr.';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public static function runtimeErrors(array $app, array $ingress, string $environment): array
    {
        $errors = [];

        if ($environment !== 'production') {
            $errors[] = 'APP_ENV must be production.';
        }

        if (($ingress['mode'] ?? null) !== 'cloud') {
            $errors[] = 'INGRESS_RUNTIME must be cloud.';
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

        $appUrl = $app['url'] ?? null;
        $url = is_string($appUrl) ? parse_url($appUrl) : false;
        if (! is_array($url)
            || strtolower((string) ($url['scheme'] ?? '')) !== 'https'
            || empty($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
            || isset($url['fragment'])
            || (isset($url['path']) && ! in_array($url['path'], ['', '/'], true))) {
            $errors[] = 'APP_URL must be the HTTPS origin for the Render service.';
        }

        if (($ingress['trusted_proxies'] ?? null) !== ['REMOTE_ADDR']) {
            $errors[] = 'TRUSTED_PROXIES must be REMOTE_ADDR on Render.';
        }

        return $errors;
    }

    /** @return list<string> */
    public static function databaseErrors(array $database): array
    {
        $errors = [];
        $connections = $database['connections'] ?? [];
        $pgsql = is_array($connections) ? ($connections['pgsql'] ?? []) : [];

        if (($database['default'] ?? null) !== 'pgsql') {
            $errors[] = 'DB_CONNECTION must be pgsql.';
        }

        if (! is_array($pgsql) || ! is_string($pgsql['url'] ?? null) || trim($pgsql['url']) === '') {
            $errors[] = 'DB_URL must contain the Neon PostgreSQL connection URL.';

            return $errors;
        }

        try {
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($pgsql);
        } catch (Throwable) {
            $errors[] = 'DB_URL must be a valid PostgreSQL connection URL.';

            return $errors;
        }

        if (($resolved['driver'] ?? null) !== 'pgsql') {
            $errors[] = 'DB_URL must use PostgreSQL.';
        }

        if (str_contains(strtolower((string) ($resolved['host'] ?? '')), '-pooler.')) {
            $errors[] = 'DB_URL must use the direct Neon endpoint for session advisory locks.';
        }

        if (($resolved['sslmode'] ?? null) !== 'verify-full') {
            $errors[] = 'DB_URL must resolve to sslmode=verify-full.';
        }

        $rootCertificate = $resolved['sslrootcert'] ?? null;
        if (! is_string($rootCertificate) || ! is_readable($rootCertificate)) {
            $errors[] = 'DB_SSLROOTCERT must point to a readable PostgreSQL CA bundle.';
        }

        return $errors;
    }

    /** @return list<string> */
    private static function authorityKeyErrors(array $ingress): array
    {
        $privateKey = is_string($ingress['authority_private_key'] ?? null)
            ? base64_decode($ingress['authority_private_key'], true)
            : false;
        $publicKey = is_string($ingress['authority_public_key'] ?? null)
            ? base64_decode($ingress['authority_public_key'], true)
            : false;

        if ($privateKey === false || $publicKey === false
            || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! hash_equals(base64_encode($privateKey), $ingress['authority_private_key'])
            || ! hash_equals(base64_encode($publicKey), $ingress['authority_public_key'])
            || ! hash_equals(sodium_crypto_sign_publickey_from_secretkey($privateKey), $publicKey)) {
            return ['Cloud authority signing keys must be valid matching Ed25519 keys.'];
        }

        return [];
    }

    public static function supportsPostgres18(int $serverVersion): bool
    {
        return $serverVersion >= 180000 && $serverVersion < 190000;
    }

    private static function hasValidApplicationKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && strlen($key) === 32;
    }
}
