<?php

namespace App\Support;

class SensitiveMailTransport
{
    public static function canSendWithoutLogging(?string $mailer = null): bool
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
        }

        return true;
    }
}
