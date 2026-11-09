<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureNodeOperatorAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('node_operator');
        Auth::shouldUse('node_operator');

        $operator = $guard->user();
        if (! $operator) {
            return redirect()->to(route('node.operator.login', absolute: false));
        }

        if (! $operator->is_active) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(route('node.operator.login', absolute: false));
        }

        return $next($request);
    }
}
