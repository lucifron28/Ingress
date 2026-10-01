<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Applied to both the mobile API login and the organizer web login via the
        // `throttle:login` route middleware.
        //
        // Every attempt counts, successful ones included: the middleware runs before
        // the controller, so the counter cannot be reset from inside the request.
        // Keying on email+IP means one account under attack does not lock out other
        // accounts from the same campus NAT, and one shared IP cannot be used to
        // spread guesses across many accounts without each account being counted.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
