<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Demo mode creates accounts with a known password. Refuse to serve at all
        // rather than run a production deployment that hands out predictable
        // credentials.
        if (config('app.demo_mode') && $this->app->environment('production')) {
            throw new LogicException(
                'INGRESS_DEMO_MODE is enabled while APP_ENV=production. Disable demo mode '
                .'and provision real accounts before serving traffic.'
            );
        }

        // Applied to both the mobile API login and the organizer web login via the
        // `throttle:login` route middleware.
        //
        // Every attempt counts, successful ones included: the middleware runs before
        // the controller, so the counter cannot be reset from inside the request.
        // Keying on email+IP means one account under attack does not lock out other
        // accounts from the same campus NAT, and one shared IP cannot be used to
        // spread guesses across many accounts without each account being counted.
        RateLimiter::for('login', function (Request $request) {
            // The limiter runs before request validation, so `email` may be any
            // shape the client sent. Only a string can key the bucket; anything
            // else shares the empty-email bucket for that IP and is rejected by
            // validation immediately afterwards.
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                (is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip()
            );
        });
    }
}
