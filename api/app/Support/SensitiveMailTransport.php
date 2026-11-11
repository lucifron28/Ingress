<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

class SensitiveMailTransport
{
    public static function canSendSafely(?string $mailer = null): bool
    {
        $mailer ??= config('mail.default');

        if (! is_string($mailer) || $mailer === '') {
            return false;
        }

        return self::mailerIsSafe($mailer, []);
    }

    /** @param  array<string, true>  $visited */
    private static function mailerIsSafe(string $name, array $visited): bool
    {
        if (isset($visited[$name])) {
            return false;
        }

        $config = config('mail.mailers.'.$name);
        if (! is_array($config)) {
            return false;
        }

        $transport = $config['transport'] ?? null;
        if (! is_string($transport) || $transport === 'log') {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $config['mailers'] ?? null;
            if (! is_array($mailers) || $mailers === []) {
                return false;
            }

            $visited[$name] = true;

            foreach ($mailers as $mailer) {
                if (! is_string($mailer) || ! self::mailerIsSafe($mailer, $visited)) {
                    return false;
                }
            }

            return true;
        }

        if ($transport === 'array') {
            return app()->environment('testing');
        }

        if (! self::hasValidSender()) {
            return false;
        }

        return match ($transport) {
            'resend' => self::hasCredential(config('services.resend.key')),
            'smtp' => self::smtpUsesTls($config),
            'postmark' => self::hasCredential(config('services.postmark.key')),
            'ses', 'ses-v2' => self::hasCredential(config('services.ses.key'))
                && self::hasCredential(config('services.ses.secret'))
                && self::hasCredential(config('services.ses.region')),
            default => false,
        };
    }

    private static function hasCredential(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function hasValidSender(): bool
    {
        $address = config('mail.from.address');

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @param  array<string, mixed>  $config */
    private static function smtpUsesTls(array $config): bool
    {
        try {
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (Throwable) {
            return false;
        }

        $scheme = $resolved['scheme'] ?? (($resolved['port'] ?? null) == 465 ? 'smtps' : 'smtp');

        return is_string($scheme) && strtolower($scheme) === 'smtps';
    }
}
