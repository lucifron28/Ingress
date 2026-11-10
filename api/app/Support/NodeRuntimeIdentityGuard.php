<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class NodeRuntimeIdentityGuard
{
    /**
     * Bind an empty PostgreSQL database to this node before running application migrations.
     * This makes a failed first migration safe to retry without adopting an unrelated database.
     */
    public function prepareForMigrations(Connection $connection, string $nodeId): void
    {
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTableListing(null, false);

        if (! $schema->hasTable('runtime_identity')) {
            if ($tables !== []) {
                throw new RuntimeException('Database contains tables but has no Event Node runtime identity.');
            }

            $schema->create('runtime_identity', function ($table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('mode', 16);
                $table->string('identity', 64);
            });
        }

        $this->assertUnboundOrNode($connection, $nodeId);

        $identity = $connection->table('runtime_identity')->where('id', 1)->first();
        if (! $identity) {
            $connection->table('runtime_identity')->insert([
                'id' => 1,
                'mode' => 'node',
                'identity' => $nodeId,
            ]);
        }

        $this->assertNode($connection, $nodeId);
    }

    public function assertUnboundOrNode(Connection $connection, string $nodeId): void
    {
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTableListing(null, false);

        if (! $schema->hasTable('runtime_identity')) {
            if ($tables !== []) {
                throw new RuntimeException('Database contains tables but has no Event Node runtime identity.');
            }

            return;
        }

        $identity = $connection->table('runtime_identity')->where('id', 1)->first();
        if (! $identity) {
            $unexpectedTables = array_diff($tables, ['migrations', 'runtime_identity']);
            if ($unexpectedTables !== []) {
                throw new RuntimeException('Database contains unbound application data.');
            }

            return;
        }

        if ($identity->mode !== 'node' || $identity->identity !== $nodeId) {
            throw new RuntimeException('Database is bound to a different runtime identity.');
        }
    }

    public function assertNode(Connection $connection, string $nodeId): void
    {
        $this->assertUnboundOrNode($connection, $nodeId);

        if (! $connection->getSchemaBuilder()->hasTable('runtime_identity')) {
            throw new RuntimeException('Event Node runtime identity is not initialized.');
        }

        $identity = $connection->table('runtime_identity')->where('id', 1)->first();
        if (! $identity || $identity->mode !== 'node' || $identity->identity !== $nodeId) {
            throw new RuntimeException('Database is not bound to the configured Event Node.');
        }
    }
}
