<?php

namespace App\Http\Middleware;

use App\Actions\NodeStaffAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureNodeStaffToken
{
    public function __construct(private NodeStaffAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('ingress.mode') !== 'node') {
            return $next($request);
        }
        if (! app()->environment(['local', 'testing']) && ! $request->secure()) {
            abort(403, 'HTTPS is required.');
        }
        if (! $request->is('api/v1/me', 'api/v1/auth/logout')) {
            abort(503, 'Node admission is not ready.');
        }
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->abilities !== ['node:staff']
            || ! DB::table('node_staff_credentials')->where('user_id', $user->id)->exists()
            || ! $this->access->assigned($user->id)) {
            abort(401, 'Unauthenticated.');
        }

        return $next($request);
    }
}
