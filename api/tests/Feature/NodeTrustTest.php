<?php

namespace Tests\Feature;

use App\Support\CloudOperatorDatabase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class NodeTrustTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_one_encrypted_random_key_and_does_not_reissue_it(): void
    {
        config(['ingress.mode' => 'cloud']);
        $this->useCloudOperatorDatabase();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $output = Artisan::output();
        $this->assertSame(1, preg_match('/INGRESS_NODE_SYNC_KEY=([A-Za-z0-9+\/=]+)/', $output, $match));
        $key = $match[1];
        $this->assertSame(32, strlen(base64_decode($key, true)));
        $stored = DB::table('event_nodes')->where('id', 'node-1')->first();
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString($key, $stored->key_ciphertext);
        $this->assertSame($key, Crypt::decryptString($stored->key_ciphertext));
        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertStringNotContainsString('INGRESS_NODE_SYNC_KEY=', Artisan::output());
        $this->assertDatabaseCount('event_nodes', 1);
    }

    public function test_revocation_erases_the_key_and_cannot_be_reversed_by_reregistering(): void
    {
        config(['ingress.mode' => 'cloud']);
        $this->useCloudOperatorDatabase();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertSame(0, Artisan::call('ingress:revoke-node', ['node_id' => 'node-1']));
        $row = DB::table('event_nodes')->where('id', 'node-1')->first();
        $this->assertNull($row->key_ciphertext);
        $this->assertNotNull($row->revoked_at);
        $this->assertSame(0, Artisan::call('ingress:revoke-node', ['node_id' => 'node-1']));
        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertNull(DB::table('event_nodes')->where('id', 'node-1')->value('key_ciphertext'));
    }

    public function test_invalid_id_and_non_cloud_runtime_cannot_register_node(): void
    {
        config(['ingress.mode' => 'cloud']);
        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => '../bad']));
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => 'node-2']));
        $this->assertDatabaseCount('event_nodes', 0);
        $this->assertDatabaseCount('runtime_identity', 0);
    }

    public function test_node_registration_rejects_cloud_configuration_errors_before_writing(): void
    {
        config(['ingress.mode' => 'cloud']);
        $database = Mockery::mock(CloudOperatorDatabase::class);
        $database->shouldReceive('configurationErrors')->once()->andReturn(['DB_URL must resolve to sslmode=verify-full.']);
        $this->app->instance(CloudOperatorDatabase::class, $database);

        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertStringContainsString('DB_URL must resolve to sslmode=verify-full.', Artisan::output());
        $this->assertDatabaseCount('event_nodes', 0);
    }

    public function test_node_bound_database_rejects_cloud_node_registration(): void
    {
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        $this->get('/login')->assertStatus(503);
        config(['ingress.mode' => 'cloud']);
        $this->assertSame(1, Artisan::call('ingress:register-node', ['node_id' => 'node-2']));
        $this->assertDatabaseCount('event_nodes', 0);
        $this->assertDatabaseHas('runtime_identity', ['mode' => 'node', 'identity' => 'node-1']);
    }

    private function useCloudOperatorDatabase(): void
    {
        DB::table('runtime_identity')->insert(['id' => 1, 'mode' => 'cloud', 'identity' => 'cloud']);

        $database = Mockery::mock(CloudOperatorDatabase::class);
        $database->shouldReceive('configurationErrors')->andReturn([]);
        $database->shouldReceive('connection')->andReturn(DB::connection());
        $database->shouldReceive('runLocked')->andReturnUsing(
            function (Connection $connection, callable $operation): bool {
                $operation();

                return true;
            },
        );
        $this->app->instance(CloudOperatorDatabase::class, $database);
    }
}
