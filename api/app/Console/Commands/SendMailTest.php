<?php

namespace App\Console\Commands;

use App\Mail\IngressMailConnectivityTest;
use App\Support\CloudOperatorDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('ingress:send-mail-test')]
#[Description('Send a static Resend connectivity message to the configured test inbox.')]
class SendMailTest extends Command
{
    public function handle(CloudOperatorDatabase $cloudDatabase): int
    {
        $errors = $cloudDatabase->configurationErrors();
        if (config('mail.default') !== 'resend') {
            $errors[] = 'MAIL_MAILER must be resend.';
        }
        if (! is_string(config('services.resend.key')) || trim(config('services.resend.key')) === '') {
            $errors[] = 'RESEND_API_KEY must be set.';
        }
        if (! is_string(config('mail.from.address')) || filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'MAIL_FROM_ADDRESS must be a valid sender address.';
        }

        $recipient = config('ingress.mail_test_recipient');
        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Set INGRESS_MAIL_TEST_RECIPIENT to the authorized test inbox before sending.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $cloudDatabase->connection();
        } catch (Throwable) {
            $this->error('Could not verify the cloud PostgreSQL 18 database and runtime identity. Confirm Render startup completed.');

            return self::FAILURE;
        }

        try {
            Mail::to($recipient)->send(new IngressMailConnectivityTest);
        } catch (Throwable) {
            $this->error('Resend could not accept the connectivity test. Check the API key and verified sender in Resend.');

            return self::FAILURE;
        }

        $this->info('Resend accepted the connectivity test request for delivery.');

        return self::SUCCESS;
    }
}
