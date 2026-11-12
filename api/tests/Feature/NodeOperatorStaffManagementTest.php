<?php

namespace Tests\Feature;

use App\Actions\ImportReceivedSnapshot;
use App\Actions\ManageNodeStaffCredential;
use App\Actions\NodeStaffPairing;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Models\Event;
use App\Models\NodeOperator;
use App\Models\User;
use App\Support\NodeStaffPairingCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class NodeOperatorStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $transferKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transferKey = base64_encode(random_bytes(32));
        config([
            'ingress.mode' => 'node',
            'ingress.node_id' => 'node-1',
            'ingress.node_sync_key' => $this->transferKey,
            'ingress.operator_allowed_cidrs' => ['127.0.0.1/32', '::1/128'],
            'app.url' => 'https://node.example.edu',
            'session.driver' => 'database',
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.same_site' => 'strict',
            'cache.default' => 'database',
        ]);
    }

    public function test_staff_page_lists_current_names_and_gates_without_contact_details_or_scanner_auth(): void
    {
        $this->importEvent();
        $operator = NodeOperator::factory()->create();

        $response = $this->actingAs($operator, 'node_operator')
            ->get('/node/operator/staff')
            ->assertOk()
            ->assertSee('Event Staff Management')
            ->assertSee('North Gate')
            ->assertSee('Local credential: not provisioned')
            ->assertSee('Type PROVISION to continue')
            ->assertDontSee('private-staff@example.test')
            ->assertDontSee('101')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/staff/events')->assertUnauthorized();
        $this->assertDatabaseCount('node_operator_audits', 0);
    }

    public function test_pairing_page_renders_a_local_qr_with_the_mobile_contract_and_existing_one_use_flow(): void
    {
        [$event, $staff] = $this->importEvent();
        app(ManageNodeStaffCredential::class)->provision(101, $event->id);
        $operator = NodeOperator::factory()->create();
        $capturedPayload = null;
        $qrCode = new NodeStaffPairingCode;
        $renderer = Mockery::mock(NodeStaffPairingCode::class)->makePartial();
        $renderer->shouldReceive('svgDataUri')->once()->andReturnUsing(function (string $payload) use (&$capturedPayload, $qrCode): string {
            $capturedPayload = $payload;

            return $qrCode->svgDataUri($payload);
        });
        $this->app->instance(NodeStaffPairingCode::class, $renderer);

        $response = $this->actingAs($operator, 'node_operator')
            ->withHeader('Host', 'attacker.example')
            ->post("/node/operator/events/{$event->id}/staff/{$staff->id}/pairing")
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('https://node.example.edu/api/v1')
            ->assertSee('North Gate')
            ->assertSee('one-use pairing secret')
            ->assertDontSee('attacker.example')
            ->assertDontSee('private-staff@example.test');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertIsString($capturedPayload);
        $payload = json_decode($capturedPayload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['v', 'url', 'node', 'token', 'expires'], array_keys($payload));
        $this->assertSame(1, $payload['v']);
        $this->assertSame('https://node.example.edu/api/v1', $payload['url']);
        $this->assertSame('node-1', $payload['node']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $payload['token']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $payload['expires']);
        $this->assertStringNotContainsString($payload['token'], $response->getContent());
        $this->assertStringNotContainsString($capturedPayload, $response->getContent());
        $this->assertDatabaseHas('node_staff_pairings', ['token_hash' => hash('sha256', $payload['token'])]);
        $this->assertDatabaseMissing('node_staff_pairings', ['token_hash' => $payload['token']]);
        $pairingRow = DB::table('node_staff_pairings')->where('user_id', $staff->id)->first();
        $pairingLifetimeSeconds = Carbon::parse($pairingRow->created_at)
            ->diffInSeconds(Carbon::parse($pairingRow->expires_at));
        $this->assertGreaterThanOrEqual(179, $pairingLifetimeSeconds);
        $this->assertLessThanOrEqual(180, $pairingLifetimeSeconds);

        $this->assertSame(1, preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $response->getContent(), $match));
        $svg = base64_decode($match[1], true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg ', $svg);
        $this->assertStringNotContainsString('href=', $svg);

        $audit = DB::table('node_operator_audits')->where('action', 'staff.pairing.generated')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $operator->id, (int) $audit->actor_id);
        $this->assertSame((int) $staff->id, (int) $audit->affected_user_id);
        $this->assertSame((int) $event->id, (int) $audit->affected_event_id);
        $this->assertSame('node-1', $audit->node_id);
        $this->assertSame('succeeded', $audit->outcome);
        $this->assertSame(
            ['id', 'actor_id', 'action', 'created_at', 'affected_user_id', 'affected_event_id', 'node_id', 'outcome'],
            Schema::getColumnListing('node_operator_audits'),
        );

        $this->postJson('/api/v1/auth/node-staff/pair', ['node_id' => $payload['node'], 'token' => $payload['token']])
            ->assertOk()
            ->assertJsonPath('node_id', $payload['node']);
        $this->postJson('/api/v1/auth/node-staff/pair', ['node_id' => $payload['node'], 'token' => $payload['token']])
            ->assertStatus(422);
    }

    public function test_provisioning_never_rotates_an_existing_credential_without_the_rotate_confirmation(): void
    {
        [$event, $staff] = $this->importEvent();
        $operator = NodeOperator::factory()->create();
        $base = "/node/operator/events/{$event->id}/staff/{$staff->id}/credential";

        $this->actingAs($operator, 'node_operator');
        $this->from('/node/operator/staff')->post("{$base}/provision")->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('node_staff_credentials', 0);

        $this->from('/node/operator/staff')->post("{$base}/provision", ['confirmation' => 'PROVISION'])
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHas('credential_issued');
        $firstSecret = session('credential_issued')['secret'];
        $firstHash = DB::table('node_staff_credentials')->where('user_id', $staff->id)->value('password_hash');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $firstSecret);
        $this->assertTrue(Hash::check($firstSecret, $firstHash));
        $this->assertNotSame($firstSecret, $firstHash);

        $scannerToken = $staff->createToken('scanner', ['node:staff'], now()->addHours(12))->plainTextToken;
        $this->from('/node/operator/staff')->post("{$base}/provision", ['confirmation' => 'PROVISION'])
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHasErrors('staff');
        $this->assertSame($firstHash, DB::table('node_staff_credentials')->where('user_id', $staff->id)->value('password_hash'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->from('/node/operator/staff')->post("{$base}/rotate", ['confirmation' => 'WRONG'])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame($firstHash, DB::table('node_staff_credentials')->where('user_id', $staff->id)->value('password_hash'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->from('/node/operator/staff')->post("{$base}/rotate", ['confirmation' => 'ROTATE'])
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHas('credential_issued');
        $rotated = session('credential_issued')['secret'];
        $rotatedHash = DB::table('node_staff_credentials')->where('user_id', $staff->id)->value('password_hash');
        $this->assertNotSame($firstHash, $rotatedHash);
        $this->assertTrue(Hash::check($rotated, $rotatedHash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($scannerToken)->getJson('/api/v1/me')->assertUnauthorized();

        $newScannerToken = $staff->createToken('scanner-2', ['node:staff'], now()->addHours(12))->plainTextToken;
        $this->from('/node/operator/staff')->post("{$base}/revoke", ['confirmation' => 'WRONG'])
            ->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('node_staff_credentials', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->from('/node/operator/staff')->post("{$base}/revoke", ['confirmation' => 'REVOKE'])
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHas('staff_message');
        $this->assertDatabaseCount('node_staff_credentials', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($newScannerToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('node_operator_audits', [
            'action' => 'staff.credential.revoked',
            'affected_user_id' => $staff->id,
            'affected_event_id' => $event->id,
            'node_id' => 'node-1',
            'outcome' => 'succeeded',
        ]);
    }

    public function test_pairing_cancellation_requires_confirmation_and_leaves_existing_sessions_untouched(): void
    {
        [$event, $staff] = $this->importEvent();
        app(ManageNodeStaffCredential::class)->provision(101, $event->id);
        $issued = app(NodeStaffPairing::class)->issue(101, $event->id);
        $staff->createToken('already-paired', ['node:staff'], now()->addHours(12));
        $operator = NodeOperator::factory()->create();
        $path = "/node/operator/events/{$event->id}/staff/{$staff->id}/pairing/cancel";

        $this->actingAs($operator, 'node_operator');
        $this->from('/node/operator/staff')->post($path)->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('node_staff_pairings', ['token_hash' => hash('sha256', $issued['token'])]);

        $this->from('/node/operator/staff')->post($path, ['confirmation' => 'CANCEL'])
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHas('staff_message');
        $this->assertDatabaseMissing('node_staff_pairings', ['token_hash' => hash('sha256', $issued['token'])]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('node_operator_audits', [
            'action' => 'staff.pairing.cancelled',
            'affected_user_id' => $staff->id,
            'affected_event_id' => $event->id,
            'outcome' => 'succeeded',
        ]);
    }

    public function test_operator_routes_remain_node_only_and_require_an_assigned_current_roster_member(): void
    {
        [$event, $staff] = $this->importEvent();
        $otherEvent = $event->replicate();
        $otherEvent->title = 'Another published event';
        $otherEvent->slug = 'another-published-event';
        $otherEvent->save();
        $operator = NodeOperator::factory()->create();
        $this->actingAs($operator, 'node_operator');

        $this->post("/node/operator/events/{$event->id}/staff/{$staff->id}/pairing")
            ->assertRedirect('/node/operator/staff')
            ->assertSessionHasErrors('staff');
        $this->assertDatabaseCount('node_staff_pairings', 0);

        app(ManageNodeStaffCredential::class)->provision(101, $event->id);
        $this->post("/node/operator/events/{$otherEvent->id}/staff/{$staff->id}/pairing")
            ->assertNotFound();
        $this->assertDatabaseCount('node_staff_pairings', 0);

        DB::table('event_staff')->delete();
        $this->post("/node/operator/events/{$event->id}/staff/{$staff->id}/pairing")
            ->assertNotFound();
        config(['ingress.mode' => 'cloud']);
        $this->get('/node/operator/staff')->assertStatus(503);
    }

    public function test_pairing_requires_the_configured_stable_https_node_address(): void
    {
        [$event, $staff] = $this->importEvent();
        app(ManageNodeStaffCredential::class)->provision(101, $event->id);
        $operator = NodeOperator::factory()->create();
        config(['app.url' => 'http://node.example.edu']);

        $this->actingAs($operator, 'node_operator')
            ->post("/node/operator/events/{$event->id}/staff/{$staff->id}/pairing")
            ->assertStatus(503)
            ->assertDontSee('one-use pairing secret');

        $this->assertDatabaseCount('node_staff_pairings', 0);
    }

    public function test_lan_demo_pairing_code_uses_the_current_private_http_node_address(): void
    {
        [$event, $staff] = $this->importEvent();
        app(ManageNodeStaffCredential::class)->provision(101, $event->id);
        $operator = NodeOperator::factory()->create();
        $capturedPayload = null;
        $qrCode = new NodeStaffPairingCode;
        $renderer = Mockery::mock(NodeStaffPairingCode::class)->makePartial();
        $renderer->shouldReceive('svgDataUri')->once()->andReturnUsing(function (string $payload) use (&$capturedPayload, $qrCode): string {
            $capturedPayload = $payload;

            return $qrCode->svgDataUri($payload);
        });
        $this->app->instance(NodeStaffPairingCode::class, $renderer);
        config([
            'ingress.mode' => 'node',
            'ingress.lan_demo' => true,
            'ingress.node_bind_address' => '192.168.55.158',
            'app.url' => 'http://192.168.55.158:8000',
            'session.secure' => false,
        ]);

        $this->actingAs($operator, 'node_operator')
            ->post("/node/operator/events/{$event->id}/staff/{$staff->id}/pairing")
            ->assertOk()
            ->assertSee('http://192.168.55.158:8000/api/v1');

        $this->assertIsString($capturedPayload);
        $payload = json_decode($capturedPayload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('http://192.168.55.158:8000/api/v1', $payload['url']);
        $this->assertSame('node-1', $payload['node']);
    }

    /** @return array{0: Event, 1: User} */
    private function importEvent(): array
    {
        $now = now()->utc();
        $payload = [
            'schema_version' => 1,
            'snapshot_id' => (string) Str::uuid(),
            'version' => 1,
            'event' => [
                'id' => 10,
                'title' => 'Event Staff Management',
                'slug' => 'event-staff-management',
                'description' => null,
                'capacity' => 10,
                'status' => 'published',
                'starts_at' => $now->toISOString(),
                'ends_at' => $now->copy()->addDay()->toISOString(),
                'registration_opens_at' => $now->copy()->subDay()->toISOString(),
                'registration_closes_at' => $now->copy()->addHour()->toISOString(),
                'registration_closed_at' => null,
                'venue' => ['id' => 50, 'name' => 'Venue Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 100, 'name' => 'Event Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [['id' => 201, 'name' => 'North Gate', 'code' => 'NORTH', 'is_active' => true]],
            'staff' => [[
                'id' => 501,
                'access_point_id' => 201,
                'user' => ['id' => 101, 'name' => 'Venue Staff', 'email' => 'private-staff@example.test', 'role' => 'student'],
            ]],
            'registrations' => [],
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $sealed = app(SnapshotEnvelope::class)->seal('node-1', $this->transferKey, $json);
        app(ReceiveSnapshot::class)->execute($sealed);
        $localEventId = app(ImportReceivedSnapshot::class)->execute(10);

        return [Event::query()->findOrFail($localEventId), User::query()->where('email', 'private-staff@example.test')->firstOrFail()];
    }
}
