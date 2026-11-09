<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\EnsureNodeStaffToken;
use App\Http\Middleware\EnsureOrganizer;
use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequireRuntime;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\TrustRenderCloudHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->append(TrustRenderCloudHosts::class);
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->web(prepend: [EnsureRuntimeDatabase::class]);
        $middleware->api(prepend: [EnsureRuntimeDatabase::class]);
        $middleware->alias([
            'organizer' => EnsureOrganizer::class,
            'staff' => EnsureStaff::class,
            'node.staff' => EnsureNodeStaffToken::class,
            'runtime' => RequireRuntime::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A throttled browser login returns to the form with a readable message
        // instead of a bare 429 page. API clients keep the JSON 429.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || ! ($request->isMethod('post') && $request->is('login'))) {
                return null;
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Too many login attempts. Please try again in a minute.',
            ])->onlyInput('email');
        });
    })->create();
