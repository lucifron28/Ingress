<?php

namespace App\Console\Commands;

use App\Support\CloudOperatorDatabase;
use Database\Seeders\VenueSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ingress:seed-cloud-reference-venues {--confirm-staging}')]
#[Description('Seed the cloud with reference venues without creating accounts or events.')]
class SeedCloudReferenceVenues extends Command
{
    public function handle(CloudOperatorDatabase $cloudDatabase): int
    {
        if (! $this->option('confirm-staging')) {
            $this->error('Pass --confirm-staging to seed the staging cloud database.');

            return self::FAILURE;
        }

        $errors = $cloudDatabase->configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $connection = $cloudDatabase->connection();
            $locked = $cloudDatabase->runLocked($connection, function () use ($connection): void {
                $connection->transaction(function (): void {
                    app(VenueSeeder::class)->run();
                });
            });
        } catch (Throwable) {
            $this->error('Cloud reference venue seeding failed. Check the cloud database state and retry.');

            return self::FAILURE;
        }

        if (! $locked) {
            $this->error('The cloud database is busy with a deployment migration. Retry after startup completes.');

            return self::FAILURE;
        }

        $this->info('Cloud reference venues are ready.');

        return self::SUCCESS;
    }
}
