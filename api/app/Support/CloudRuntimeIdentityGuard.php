<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class CloudRuntimeIdentityGuard
{
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
