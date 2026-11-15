<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if (config('app.debug') && $this->app->environment('production')) {
            throw new LogicException('APP_DEBUG must be disabled while APP_ENV=production.');
        }

        RateLimiter::for('node-staff-pair', fn (Request $request) => Limit::perMinute(20)->by('node-pair:'.$request->ip()));

        RateLimiter::for('node-staff-login', function (Request $request) {
            $email = $request->input('email');
            $normalized = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('node-staff:'.hash('sha256', $normalized).'|'.$request->ip()),
                Limit::perMinute(30)->by('node-staff-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('node-operator-login', function (Request $request) {
            $email = $request->input('email');
            $normalized = is_string($email) ? Str::lower(trim($email)) : '';
            $peerAddress = $request->server->get('REMOTE_ADDR');
            $ipKey = is_string($peerAddress) ? hash('sha256', $peerAddress) : 'unknown';

            return [
                Limit::perMinute(5)->by('node-operator:'.hash('sha256', $normalized).'|'.$ipKey),
                Limit::perMinute(30)->by('node-operator-ip:'.$ipKey),
            ];
        });

        RateLimiter::for('node-operator-actions', function (Request $request) {
            $operatorId = Auth::guard('node_operator')->id();
            $peerAddress = $request->server->get('REMOTE_ADDR');
            $peerKey = is_string($peerAddress) ? hash('sha256', $peerAddress) : 'unknown';

            return Limit::perMinute(30)->by('node-operator-action:'.($operatorId ?? 'unknown').'|'.$peerKey);
        });

        RateLimiter::for('student-firebase-session', fn (Request $request) => Limit::perMinute(10)->by('student-firebase-session:'.$request->ip()));
        RateLimiter::for('manager-invitation', fn (Request $request) => Limit::perMinute(30)->by('manager-invitation:'.$request->ip()));

        RateLimiter::for('ticket-transfer', fn (Request $request) => [
            Limit::perMinute(10)->by('ticket-transfer-user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(30)->by('ticket-transfer-ip:'.$request->ip()),
        ]);

        RateLimiter::for('public-registration', function (Request $request) {
            $event = $request->route('event');
            $eventKey = match (true) {
                is_object($event) && isset($event->id) => (string) $event->id,
                is_string($event) || is_int($event) => hash('sha256', (string) $event),
                default => 'unknown',
            };

            return [
                Limit::perMinute(5)->by('public-registration-event:'.$request->ip().'|'.$eventKey),
                Limit::perMinute(20)->by('public-registration-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('public-ticket-pass', fn (Request $request) => Limit::perMinute(30)->by('public-ticket-pass:'.$request->ip()));
        RateLimiter::for('public-ticket-download', fn (Request $request) => Limit::perMinute(20)->by('public-ticket-download:'.$request->ip()));
        RateLimiter::for('guest-ticket-redemption', fn (Request $request) => Limit::perMinute(10)->by('guest-ticket-redemption:'.$request->ip()));

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
