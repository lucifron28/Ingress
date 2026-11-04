<?php

namespace Tests\Feature;

use App\Actions\ActivateEventAuthority;
use App\Actions\AuthorityGrant;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueNodeWalkIn;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class NodeAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private string $qr;

    private string $token;

    private int $eventId;

    private int $gateId;

    private int $otherGateId;

    private string $grant;

    protected function setUp(): void
    {
        parent::setUp();
        $key = base64_encode(random_bytes(32));
        $pair = sodium_crypto_sign_keypair();
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $key,
            'ingress.authority_private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $secret = str_repeat('a', 64);
        $this->qr = 'ingress:v1:ticket-1:'.$secret;
        $payload = [
            'schema_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'version' => 1,
            'event' => [
                'id' => 10, 'title' => 'Event', 'slug' => 'event', 'description' => null, 'capacity' => 10,
                'status' => 'published', 'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
                'registration_opens_at' => now()->subDay()->toISOString(), 'registration_closes_at' => now()->toISOString(),
                'registration_closed_at' => now()->toISOString(),
                'venue' => ['id' => 50, 'name' => 'Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 100, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [
                ['id' => 1, 'name' => 'Gate A', 'code' => 'A', 'is_active' => true],
                ['id' => 2, 'name' => 'Gate B', 'code' => 'B', 'is_active' => true],
            ],
            'staff' => [['id' => 1, 'access_point_id' => 1,
                'user' => ['id' => 101, 'name' => 'Assigned Admin', 'email' => 'staff@example.test', 'role' => 'admin']]],
            'registrations' => [['id' => 1, 'status' => 'confirmed', 'created_at' => now()->toISOString(), 'cancelled_at' => null,
                'attendee' => ['id' => 102, 'name' => 'Attendee', 'email' => 'attendee@example.test', 'student_id' => '123'],
                'ticket' => ['id' => 1, 'public_id' => 'ticket-1', 'qr_hash' => hash('sha256', $secret), 'status' => 'active',
                    'credential_version' => 1, 'issued_at' => now()->toISOString(), 'revoked_at' => null]]],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('node-1', $key, $json));
        $this->eventId = app(ImportReceivedSnapshot::class)->execute(10);
        $this->gateId = (int) DB::table('node_import_ids')->where('kind', 'gate')->where('cloud_id', 1)->value('local_id');
        $this->otherGateId = (int) DB::table('node_import_ids')->where('kind', 'gate')->where('cloud_id', 2)->value('local_id');
        $this->assertSame(0, Artisan::call('ingress:provision-node-staff', ['cloud_user_id' => '101']));
        preg_match('/NODE_STAFF_PASSWORD=([a-f0-9]{32})/', Artisan::output(), $password);
        $this->token = $this->postJson('/api/v1/auth/node-staff/login', [
            'email' => 'staff@example.test', 'password' => $password[1],
        ])->assertOk()->json('token');
        $import = DB::table('node_event_imports')->where('event_id', $this->eventId)->first();
        $this->grant = app(AuthorityGrant::class)->sign([
            'protocol' => 1, 'node_id' => 'node-1', 'snapshot_id' => $import->snapshot_id,
            'event_id' => 10, 'version' => 1, 'sha256' => $import->sha256,
        ]);
    }

    private function scan(string $endpoint, int $gate, string $id = 'scan-1'): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/v1/staff/events/{$this->eventId}/admissions{$endpoint}", [
            'access_point_id' => $gate, 'qr_payload' => $this->qr, 'client_scan_id' => $id,
        ]);
    }

    public function test_node_requires_authority_assignment_and_explicit_confirmation(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/staff/events')->assertOk()->assertExactJson(['events' => []]);
        $this->scan('/preview', $this->gateId)->assertForbidden()->assertJsonPath('status', 'node_authority_unavailable');
        $this->scan('', $this->gateId)->assertForbidden();
        $this->assertDatabaseCount('admissions', 0);
        app(ActivateEventAuthority::class)->execute($this->grant);
        $this->withToken($this->token)->getJson('/api/v1/staff/events')->assertOk()
            ->assertJsonCount(1, 'events')->assertJsonCount(1, 'events.0.access_points');
        $this->scan('/preview', $this->otherGateId)->assertForbidden()->assertJsonPath('status', 'gate_mismatch');
        $this->scan('/preview', $this->gateId)->assertOk()->assertJsonPath('status', 'valid');
        $this->assertDatabaseCount('admissions', 0);
        $this->scan('', $this->gateId)->assertOk()->assertJsonPath('status', 'admitted');
        $this->scan('', $this->gateId)->assertOk()->assertJsonPath('status', 'admitted');
        $this->scan('', $this->gateId, 'scan-2')->assertStatus(409)->assertJsonPath('status', 'already_admitted');
        $this->assertDatabaseCount('admissions', 1);
    }

    public function test_revoked_assignment_and_corrupt_authority_fail_closed_even_on_replay(): void
    {
        app(ActivateEventAuthority::class)->execute($this->grant);
        $this->scan('', $this->gateId)->assertOk();
        DB::table('node_event_authorities')->where('event_id', $this->eventId)->update(['grant' => 'invalid']);
        $this->scan('', $this->gateId)->assertForbidden()->assertJsonPath('status', 'node_authority_unavailable');
        DB::table('node_event_authorities')->where('event_id', $this->eventId)->update(['grant' => $this->grant]);
        DB::table('event_staff')->where('event_id', $this->eventId)->delete();
        $this->scan('', $this->gateId)->assertUnauthorized();
        $this->assertDatabaseCount('admissions', 1);
    }

    public function test_walk_in_issuance_is_idempotent_and_respects_capacity(): void
    {
        app(ActivateEventAuthority::class)->execute($this->grant);
        $token = PersonalAccessToken::findToken($this->token);
        $staff = $token->tokenable->withAccessToken($token);
        $action = app(IssueNodeWalkIn::class);
        $requestId = (string) Str::uuid();
        $ticket = $action->execute($staff, $this->eventId, $requestId, 'Walk In', 'walkin@example.test', 'student', '456');
        $retry = $action->execute($staff, $this->eventId, $requestId, 'Walk In', 'walkin@example.test', 'student', '456');
        $this->assertSame($ticket->id, $retry->id);
        $this->assertSame($ticket->getQrPayload(), $retry->getQrPayload());
        $this->assertSame('456', $ticket->registration->attendeeStudentId());
        $this->assertDatabaseCount('node_walk_in_issuances', 1);
        $this->assertDatabaseCount('admissions', 0);

        DB::table('events')->where('id', $this->eventId)->update(['capacity' => 2]);
        try {
            $action->execute($staff, $this->eventId, (string) Str::uuid(), 'Other', 'other@example.test', 'guest', null);
            $this->fail('A full event accepted a walk-in.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('capacity', $exception->errors()['walk_in'][0]);
        }
        $this->assertDatabaseCount('node_walk_in_issuances', 1);
    }

    public function test_walk_in_endpoint_requires_authority_and_uses_the_normal_scanner(): void
    {
        $details = ['client_request_id' => (string) Str::uuid(), 'name' => 'Walk In',
            'email' => 'walkin@example.test', 'kind' => 'guest'];
        $url = "/api/v1/staff/events/{$this->eventId}/walk-ins";
        $this->withToken($this->token)->postJson($url, $details)->assertForbidden();
        app(ActivateEventAuthority::class)->execute($this->grant);
        $pass = $this->withToken($this->token)->postJson($url, $details)->assertOk()->json();
        $this->assertSame($pass['qr_payload'], $this->withToken($this->token)->postJson($url, $details)->assertOk()->json('qr_payload'));
        $this->withToken($this->token)->postJson("/api/v1/staff/events/{$this->eventId}/admissions/preview", [
            'access_point_id' => $this->gateId, 'qr_payload' => $pass['qr_payload'],
        ])->assertOk()->assertJsonPath('ticket.attendee_name', 'Walk In');
        $this->assertDatabaseCount('admissions', 0);
        $this->withToken($this->token)->postJson("/api/v1/staff/events/{$this->eventId}/admissions", [
            'access_point_id' => $this->gateId, 'qr_payload' => $pass['qr_payload'], 'client_scan_id' => 'walk-in-scan',
        ])->assertOk()->assertJsonPath('status', 'admitted');
        $this->assertDatabaseCount('admissions', 1);
    }
}
