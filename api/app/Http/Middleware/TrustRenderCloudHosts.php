<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrustRenderCloudHosts
{
    public function handle(Request $request, Closure $next): mixed
    {
        $patterns = self::patterns();
        Request::setTrustedHosts($patterns);

        try {
            if ($patterns !== []) {
                $request->getHost();
            }

            return $next($request);
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    /** @return list<string> */
    public static function patterns(): array
    {
        if (! app()->environment('production') || config('ingress.mode') !== 'cloud') {
            return [];
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $configuredHosts = config('ingress.trusted_hosts', []);
        $hosts = array_values(array_unique(array_filter([
            ...(is_string($appHost) ? [$appHost] : []),
            ...(is_array($configuredHosts) ? $configuredHosts : []),
        ], static fn (mixed $host): bool => is_string($host) && $host !== '')));

        return array_map(static fn (string $host): string => '^'.preg_quote($host, '/').'$', $hosts);
    }
}
