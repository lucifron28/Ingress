<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class CloudOperatorDatabase
{
    public function __construct(
        private readonly CloudRuntimeIdentityGuard $identityGuard,
        private readonly PostgresAdvisoryMigrationLock $migrationLock,
    ) {}

    /** @return list<string> */
    public function configurationErrors(): array
    {
        return [
            ...RenderDeploymentConfiguration::runtimeErrors(
                config('app'),
                config('ingress'),
                app()->environment(),
            ),
            ...RenderDeploymentConfiguration::databaseErrors(config('database')),
        ];
    }

    public function connection(): Connection
    {
        $connection = DB::connection('pgsql');
        if ($connection->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('The cloud operator requires PostgreSQL.');
        }

        $version = (int) $connection->scalar("select current_setting('server_version_num')");
        if (! RenderDeploymentConfiguration::supportsPostgres18($version)) {
            throw new \RuntimeException('The cloud operator requires PostgreSQL 18.');
        }

        $this->identityGuard->assertCloud($connection);

        return $connection;
    }

    public function runLocked(Connection $connection, callable $operation): bool
    {
        return $this->migrationLock->run($connection, $operation, 30);
    }
}
