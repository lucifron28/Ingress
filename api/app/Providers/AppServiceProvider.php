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
        // Predictable demo credentials are never allowed in production.
        if (config('app.demo_mode') && $this->app->environment('production')) {
            throw new LogicException(
                'INGRESS_DEMO_MODE is enabled while APP_ENV=production. Disable demo mode '
                .'and provision real accounts before serving traffic.'
            );
        }

        RateLimiter::for('student-email', function (Request $request) {
            $email = $request->input('email');
            $normalized = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinutes(10, 3)->by('student-email:'.hash('sha256', $normalized)),
                Limit::perMinute(20)->by('student-email-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('student-complete', fn (Request $request) => Limit::perMinute(10)->by('student-complete:'.$request->ip()));

        // Email + IP keeps the limiter account-specific without penalizing the whole campus NAT.
        RateLimiter::for('login', function (Request $request) {
            // Middleware runs before validation, so malformed email input must still produce a safe key.
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                (is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip()
            );
        });
    }
}
