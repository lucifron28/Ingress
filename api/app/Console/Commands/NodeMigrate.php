<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use App\Support\NodeDockerDeploymentConfiguration;
use App\Support\NodeRuntimeIdentityGuard;
use App\Support\PostgresAdvisoryMigrationLock;
use App\Support\RenderDeploymentConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('ingress:node-migrate')]
#[Description('Safely bind and migrate the private PostgreSQL database for this Event Node.')]
class NodeMigrate extends Command
{
    public function handle(
        NodeDockerDeploymentConfiguration $configuration,
        PostgresAdvisoryMigrationLock $migrationLock,
        NodeRuntimeIdentityGuard $identityGuard,
        BindRuntimeDatabase $runtime,
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
            $nodeId = (string) config('ingress.node_id');
            $locked = $migrationLock->run($connection, function () use ($connection, $identityGuard, $runtime, $nodeId): void {
                $serverVersion = (int) $connection->scalar("select current_setting('server_version_num')");
                if (! RenderDeploymentConfiguration::supportsPostgres18($serverVersion)) {
                    throw new RuntimeException('PostgreSQL 18 is required.');
                }

                $identityGuard->prepareForMigrations($connection, $nodeId);

                if ($this->callSilent('migrate', [
                    '--database' => 'pgsql',
                    '--force' => true,
                    '--no-interaction' => true,
                ]) !== self::SUCCESS) {
                    throw new RuntimeException('Database migrations failed.');
                }

                $runtime->execute();
                $identityGuard->assertNode($connection, $nodeId);
            });

            if (! $locked) {
                Log::critical('Event Node startup could not acquire the database migration lock.');
                $this->error('Could not acquire the database migration lock before the startup timeout.');

                return self::FAILURE;
            }

            $this->info('Event Node database migrations and runtime identity are ready.');

            return self::SUCCESS;
        } catch (Throwable) {
            Log::critical('Event Node database preparation failed.');
            $this->error('Event Node database preparation failed. Check the PostgreSQL service, migrations, and runtime identity.');

            return self::FAILURE;
        }
    }
}
