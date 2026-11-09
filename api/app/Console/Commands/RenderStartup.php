<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use App\Support\CloudRuntimeIdentityGuard;
use App\Support\PostgresAdvisoryMigrationLock;
use App\Support\RenderDeploymentConfiguration;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('ingress:render-start')]
class RenderStartup extends Command
{
    protected $description = 'Prepare and verify the Render cloud database before serving HTTP traffic.';

    public function handle(
        RenderDeploymentConfiguration $configuration,
        PostgresAdvisoryMigrationLock $migrationLock,
        CloudRuntimeIdentityGuard $identityGuard,
    ): int {
        $errors = $configuration->errors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $connection = DB::connection('pgsql');
            $locked = $migrationLock->run($connection, function () use ($connection, $identityGuard): void {
                $serverVersion = (int) $connection->scalar("select current_setting('server_version_num')");
                if (! RenderDeploymentConfiguration::supportsPostgres18($serverVersion)) {
                    throw new RuntimeException('PostgreSQL 18 is required.');
                }

                // Refuse another runtime's database before cloud migrations can change its schema.
                $identityGuard->assertUnboundOrCloud($connection);

                if ($this->callSilent('migrate', ['--database' => 'pgsql', '--force' => true]) !== self::SUCCESS) {
                    throw new RuntimeException('Database migrations failed.');
                }

                app(BindRuntimeDatabase::class)->execute();
            });

            if (! $locked) {
                Log::critical('Render startup could not acquire the database migration lock.');
                $this->error('Could not acquire the database migration lock before the startup timeout.');

                return self::FAILURE;
            }

            $this->info('Cloud database migrations and runtime identity are ready.');

            return self::SUCCESS;
        } catch (Throwable) {
            Log::critical('Render startup database preparation failed.');
            $this->error('Render startup failed. Database migrations or cloud runtime identity validation did not complete.');

            return self::FAILURE;
        }
    }
}
