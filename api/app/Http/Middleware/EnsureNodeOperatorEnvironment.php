<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureNodeOperatorEnvironment
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ingress.mode') === 'node', 503, 'The operator console is unavailable.');

        $peerAddress = $request->server->get('REMOTE_ADDR');
        $allowedCidrs = config('ingress.operator_allowed_cidrs');
        abort_unless(
            is_string($peerAddress) && filter_var($peerAddress, FILTER_VALIDATE_IP) !== false
            && is_array($allowedCidrs) && $allowedCidrs !== [],
            503,
            'The operator console is not configured safely.',
        );

        $peerIsAllowed = false;
        foreach ($allowedCidrs as $cidr) {
            if (! is_string($cidr) || ! $this->isValidCidr(trim($cidr))) {
                abort(503, 'The operator console is not configured safely.');
            }

            try {
                $peerIsAllowed = IpUtils::checkIp($peerAddress, $cidr) || $peerIsAllowed;
            } catch (\Throwable) {
                abort(503, 'The operator console is not configured safely.');
            }
        }

        abort_unless($peerIsAllowed, 403, 'The operator console is unavailable from this address.');

        if (! app()->environment('local', 'testing')) {
            abort_unless($request->isSecure(), 403, 'The operator console requires HTTPS.');
        }

        $secureSettings = config('session.driver') === 'database'
            && config('session.secure') === true
            && config('session.http_only') === true
            && config('session.encrypt') === true
            && config('session.same_site') === 'strict'
            && config('cache.default') === 'database';
        abort_unless($secureSettings, 503, 'The operator console security settings are incomplete.');

        return $next($request);
    }

    private function isValidCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (count($parts) === 1) {
            return true;
        }

        if (! ctype_digit($parts[1])) {
            return false;
        }

        $maximumPrefix = str_contains($parts[0], ':') ? 128 : 32;

        return (int) $parts[1] <= $maximumPrefix;
    }
}
