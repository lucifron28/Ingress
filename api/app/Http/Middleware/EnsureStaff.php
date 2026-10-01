<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isStaff()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized. Event staff privileges required.',
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Event staff privileges required.',
            ]);
        }

        return $next($request);
    }
}
