<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRuntime
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        abort_unless(in_array(config('ingress.mode'), $allowed, true), 503, 'This operation is unavailable on this runtime.');

        return $next($request);
    }
}
