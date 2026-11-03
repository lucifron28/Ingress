<?php

namespace Tests\Feature;

use App\Actions\ImportReceivedSnapshot;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NodeStaffAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $transferKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transferKey = base64_encode(random_bytes(32));
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $this->transferKey]);
    }

    private function stage(int $version = 1, bool $assigned = true): void
    {
        $payload = [
            'schema_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'version' => $version,
            'event' => [
                'id' => 10, 'title' => 'Event', 'slug' => 'event', 'description' => null, 'capacity' => 10,
                'status' => 'published', 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
                'registration_opens_at' => now()->subDay()->toISOString(), 'registration_closes_at' => now()->addHour()->toISOString(),
                'registration_closed_at' => now()->toISOString(),
                'venue' => ['id' => 50, 'name' => 'Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 100, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [],
            'staff' => $assigned ? [['id' => 1, 'access_point_id' => null,
                'user' => ['id' => 101, 'name' => 'Student Staff', 'email' => 'staff@example.test', 'role' => 'student']]] : [],
            'registrations' => [],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('node-1', $this->transferKey, $json));
    }

    private function provision(): string
    {
        $this->assertSame(0, Artisan::call('ingress:provision-node-staff', ['cloud_user_id' => '101']));
        $this->assertSame(1, preg_match('/NODE_STAFF_PASSWORD=([a-f0-9]{32})/', Artisan::output(), $match));

        return $match[1];
    }

    private function signIn(string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/node-staff/login', [
            'email' => 'Staff@Example.Test', 'password' => $password, 'device_name' => 'scanner',
        ]);
    }

    public function test_assigned_staff_can_sign_in_with_a_local_credential_and_sign_out(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $this->assertSame(0, Artisan::call('ingress:list-node-staff'));
        $listing = Artisan::output();
        $this->assertStringContainsString('staff@example.test', $listing);
        $this->assertStringContainsString('101', $listing);
        $password = $this->provision();
        $staff = User::where('email', 'staff@example.test')->firstOrFail();
        $hash = DB::table('node_staff_credentials')->where('user_id', $staff->id)->value('password_hash');
        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::check($password, $hash));
        $response = $this->signIn($password)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('node_staff', true)->assertJsonPath('user.role', 'student');
        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $tokenRecord = $staff->tokens()->firstOrFail();
        $this->assertSame(['node:staff'], $tokenRecord->abilities);
        $this->assertNotNull($tokenRecord->expires_at);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $staff->id);
        $this->withToken($token)->getJson('/api/v1/events')->assertStatus(503);
        $eventId = DB::table('node_event_imports')->where('cloud_event_id', 10)->value('event_id');
        $this->withToken($token)->postJson("/api/v1/staff/events/{$eventId}/admissions/preview")->assertStatus(503);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_rotation_and_revocation_invalidate_old_passwords_and_tokens(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $first = $this->provision();
        $token = $this->signIn($first)->assertOk()->json('token');
        $second = $this->provision();
        $this->assertNotSame($first, $second);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->signIn($first)->assertStatus(422);
        $newToken = $this->signIn($second)->assertOk()->json('token');
        $this->assertSame(0, Artisan::call('ingress:revoke-node-staff', ['cloud_user_id' => '101']));
        $this->withToken($newToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->signIn($second)->assertStatus(422);
        $this->assertDatabaseCount('node_staff_credentials', 0);
    }

    public function test_removed_or_stale_assignment_cannot_authenticate(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $password = $this->provision();
        $token = $this->signIn($password)->assertOk()->json('token');
        $this->stage(2, false);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->signIn($password)->assertStatus(422);
        app(ImportReceivedSnapshot::class)->execute(10);
        $this->signIn($password)->assertStatus(422);
        $this->assertSame(1, Artisan::call('ingress:provision-node-staff', ['cloud_user_id' => '101']));
        $this->assertSame(0, Artisan::call('ingress:revoke-node-staff', ['cloud_user_id' => '101']));
    }

    public function test_other_credentials_and_other_runtimes_cannot_use_node_authentication(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $this->provision();
        $staff = User::where('email', 'staff@example.test')->firstOrFail();
        $wildcard = $staff->createToken('wildcard')->plainTextToken;
        $this->withToken($wildcard)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.test', 'password' => 'anything'])->assertStatus(503);
        $this->assertSame(1, Artisan::call('ingress:provision-node-staff', ['cloud_user_id' => '100']));
        config(['ingress.mode' => 'cloud']);
        DB::table('runtime_identity')->delete();
        $this->postJson('/api/v1/auth/node-staff/login', ['email' => 'staff@example.test', 'password' => str_repeat('a', 32)])->assertStatus(503);
    }

    public function test_staff_tokens_expire_and_login_attempts_are_throttled(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $password = $this->provision();
        $token = $this->signIn($password)->assertOk()->json('token');
        $this->travel(13)->hours();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        for ($i = 0; $i < 5; $i++) {
            $this->signIn(str_repeat('a', 32))->assertStatus(422);
        }
        $this->signIn(str_repeat('a', 32))->assertStatus(429);
    }

    public function test_node_login_requires_https_outside_local_or_testing(): void
    {
        $this->stage();
        app(ImportReceivedSnapshot::class)->execute(10);
        $password = $this->provision();
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->postJson('http://example.test/api/v1/auth/node-staff/login', ['email' => 'staff@example.test', 'password' => $password])->assertForbidden();
        $this->postJson('https://example.test/api/v1/auth/node-staff/login', ['email' => 'staff@example.test', 'password' => $password])->assertOk();
    }
}
