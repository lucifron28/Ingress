<?php

namespace App\Console\Commands;

use App\Support\NodeDockerDeploymentConfiguration;
use App\Support\NodeRuntimeIdentityGuard;
use App\Support\RenderDeploymentConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('ingress:node-start')]
#[Description('Verify the production configuration and PostgreSQL runtime identity before serving this Event Node.')]
class NodeStart extends Command
{
    public function handle(
        NodeDockerDeploymentConfiguration $configuration,
        NodeRuntimeIdentityGuard $identityGuard,
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
            $serverVersion = (int) $connection->scalar("select current_setting('server_version_num')");
            if (! RenderDeploymentConfiguration::supportsPostgres18($serverVersion)) {
                $this->error('PostgreSQL 18 is required.');

                return self::FAILURE;
            }

            $identityGuard->assertNode($connection, (string) config('ingress.node_id'));

            return self::SUCCESS;
        } catch (Throwable) {
            Log::critical('Event Node startup verification failed.');
            $this->error('Event Node startup verification failed. Check the PostgreSQL service and runtime identity.');

            return self::FAILURE;
        }
    }
}
