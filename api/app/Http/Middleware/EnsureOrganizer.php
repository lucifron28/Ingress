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

        if (! $user || ! $user->isOrganizer()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized. Organizer privileges required.',
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Organizer credentials required to access this area.',
            ]);
        }

        return $next($request);
    }
}
