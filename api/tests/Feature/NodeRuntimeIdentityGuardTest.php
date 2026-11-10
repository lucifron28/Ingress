<?php

namespace Tests\Feature;

use App\Support\NodeRuntimeIdentityGuard;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NodeRuntimeIdentityGuardTest extends TestCase
{
    private string $connectionName = 'node_identity_guard';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.$this->connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($this->connectionName);
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);

        parent::tearDown();
    }

    public function test_empty_database_is_allowed_for_initial_migrations(): void
    {
        app(NodeRuntimeIdentityGuard::class)->assertUnboundOrNode($this->connection(), 'venue-node-1');

        $this->assertSame([], $this->connection()->getSchemaBuilder()->getTableListing());
    }

    public function test_empty_database_is_bound_before_migrations_start(): void
    {
        $guard = app(NodeRuntimeIdentityGuard::class);
        $connection = $this->connection();

        $guard->prepareForMigrations($connection, 'venue-node-1');

        $this->assertSame('node', $connection->table('runtime_identity')->value('mode'));
        $this->assertSame('venue-node-1', $connection->table('runtime_identity')->value('identity'));
        $guard->assertNode($connection, 'venue-node-1');
    }

    public function test_unbound_database_with_application_tables_is_not_adopted_for_migrations(): void
    {
        $connection = $this->connection();
        $connection->getSchemaBuilder()->create('events', function ($table): void {
            $table->id();
        });

        $this->expectException(RuntimeException::class);
        app(NodeRuntimeIdentityGuard::class)->prepareForMigrations($connection, 'venue-node-1');
    }

    public function test_existing_database_bound_to_the_same_node_is_allowed(): void
    {
        $connection = $this->connection();
        $this->createIdentityTable($connection);
        $connection->table('runtime_identity')->insert([
            'id' => 1,
            'mode' => 'node',
            'identity' => 'venue-node-1',
        ]);

        app(NodeRuntimeIdentityGuard::class)->assertUnboundOrNode($connection, 'venue-node-1');
        app(NodeRuntimeIdentityGuard::class)->assertNode($connection, 'venue-node-1');

        $this->assertSame('venue-node-1', $connection->table('runtime_identity')->value('identity'));
    }

    public function test_existing_database_bound_to_another_runtime_is_rejected(): void
    {
        $connection = $this->connection();
        $this->createIdentityTable($connection);
        $connection->table('runtime_identity')->insert([
            'id' => 1,
            'mode' => 'cloud',
            'identity' => 'cloud',
        ]);

        $this->expectException(RuntimeException::class);
        app(NodeRuntimeIdentityGuard::class)->assertUnboundOrNode($connection, 'venue-node-1');
    }

    public function test_unbound_database_with_application_tables_is_rejected(): void
    {
        $connection = $this->connection();
        $connection->getSchemaBuilder()->create('events', function ($table): void {
            $table->id();
        });

        $this->expectException(RuntimeException::class);
        app(NodeRuntimeIdentityGuard::class)->assertUnboundOrNode($connection, 'venue-node-1');
    }

    public function test_partial_first_migration_can_resume_only_with_runtime_tables(): void
    {
        $connection = $this->connection();
        $connection->getSchemaBuilder()->create('migrations', function ($table): void {
            $table->id();
        });
        $this->createIdentityTable($connection);

        $this->assertNull(app(NodeRuntimeIdentityGuard::class)->assertUnboundOrNode($connection, 'venue-node-1'));
    }

    private function connection(): Connection
    {
        return DB::connection($this->connectionName);
    }

    private function createIdentityTable(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('runtime_identity', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('mode');
            $table->string('identity');
        });
    }
}
