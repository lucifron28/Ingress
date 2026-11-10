<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizer
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasEventManagementAccess()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized. Event management access required.',
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Sign in with an account that can manage events.',
            ]);
        }

        return $next($request);
    }
}
