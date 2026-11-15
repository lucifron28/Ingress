<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class CloudRuntimeIdentityGuard
{
    public function assertCloud(Connection $connection): void
    {
        if (! $connection->getSchemaBuilder()->hasTable('runtime_identity')) {
            throw new RuntimeException('Cloud database runtime identity is not initialized.');
        }

        $identity = $connection->table('runtime_identity')->where('id', 1)->first();

        if (! $identity || $identity->mode !== 'cloud' || $identity->identity !== 'cloud') {
            throw new RuntimeException('Database is not bound to the Ingress cloud runtime.');
        }
    }

    public function assertUnboundOrCloud(Connection $connection): void
    {
        if (! $connection->getSchemaBuilder()->hasTable('runtime_identity')) {
            return;
        }

        $identity = $connection->table('runtime_identity')->where('id', 1)->first();

        if ($identity && ($identity->mode !== 'cloud' || $identity->identity !== 'cloud')) {
            throw new RuntimeException('Database is bound to a different runtime.');
        }
    }
}
