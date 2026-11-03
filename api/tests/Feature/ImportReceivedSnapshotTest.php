<?php

namespace Tests\Feature;

use App\Actions\ImportReceivedSnapshot;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportReceivedSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = base64_encode(random_bytes(32));
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $this->secret]);
    }

    private function payload(int $eventId = 10, int $version = 1): array
    {
        $date = '2026-01-01T00:00:00.000000Z';

        return [
            'schema_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'version' => $version,
            'event' => [
                'id' => $eventId, 'title' => 'Event', 'slug' => 'event-'.$eventId, 'description' => null, 'capacity' => 10,
                'status' => 'published', 'starts_at' => $date, 'ends_at' => '2026-01-02T00:00:00.000000Z',
                'registration_opens_at' => $date, 'registration_closes_at' => '2026-01-02T00:00:00.000000Z',
                'registration_closed_at' => null, 'venue' => ['id' => 50, 'name' => 'Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 100, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [['id' => $eventId * 10, 'name' => 'Gate', 'code' => 'G1', 'is_active' => true]],
            'staff' => [['id' => $eventId * 10, 'access_point_id' => $eventId * 10, 'user' => ['id' => 101, 'name' => 'Staff', 'email' => 'staff@example.test', 'role' => 'staff']]],
            'registrations' => [[
                'id' => $eventId * 10, 'status' => 'confirmed', 'created_at' => $date, 'cancelled_at' => null,
                'attendee' => ['id' => 102, 'name' => 'Attendee', 'email' => 'attendee@example.test', 'student_id' => 'student-1'],
                'ticket' => ['id' => $eventId * 10, 'public_id' => 'ticket-'.$eventId, 'qr_hash' => hash('sha256', 'private-'.$eventId), 'status' => 'active', 'credential_version' => 1, 'issued_at' => $date, 'revoked_at' => null],
            ]],
        ];
    }

    private function stage(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $sealed = app(SnapshotEnvelope::class)->seal('node-1', $this->secret, $json);
        app(ReceiveSnapshot::class)->execute($sealed);
    }

    public function test_import_maps_cloud_ids_and_is_idempotent_without_enabling_admission(): void
    {
        User::factory()->create(['email' => 'unrelated@example.test']);
        $payload = $this->payload();
        $this->stage($payload);
        $this->assertSame(0, Artisan::call('ingress:import-snapshot', ['event_id' => '10']));
        $this->assertStringContainsString('Admission remains disabled', Artisan::output());
        $import = DB::table('node_event_imports')->where('cloud_event_id', 10)->first();
        $this->assertNotNull($import);
        $this->assertNotSame(10, $import->event_id);
        $ticket = DB::table('tickets')->first();
        $this->assertSame($payload['registrations'][0]['ticket']['qr_hash'], $ticket->qr_hash);
        $this->assertNull($ticket->encrypted_secret);
        $this->assertDatabaseHas('node_import_ids', ['kind' => 'ticket', 'cloud_id' => 100, 'local_id' => $ticket->id]);
        $this->assertDatabaseHas('node_import_ids', ['kind' => 'gate', 'cloud_id' => 100]);
        $this->assertSame((int) $import->event_id, app(ImportReceivedSnapshot::class)->execute(10));
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->actingAs(User::where('email', 'staff@example.test')->firstOrFail())->postJson("/api/v1/staff/events/{$import->event_id}/admissions/preview", [])->assertStatus(503);
    }

    public function test_shared_users_and_venues_are_reused_across_events(): void
    {
        $this->stage($this->payload(10));
        $this->stage($this->payload(11));
        $first = app(ImportReceivedSnapshot::class)->execute(10);
        $second = app(ImportReceivedSnapshot::class)->execute(11);
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('venues', 1);
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_identity_conflict_rolls_back_the_entire_import(): void
    {
        User::factory()->create(['email' => 'attendee@example.test']);
        $this->stage($this->payload());
        $this->assertSame(1, Artisan::call('ingress:import-snapshot', ['event_id' => '10']));
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('node_import_ids', 0);
        $this->assertDatabaseCount('node_event_imports', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('venues', 0);
    }

    public function test_corrupt_staged_payload_is_rejected_before_import(): void
    {
        $this->stage($this->payload());
        DB::table('received_snapshots')->update(['payload_ciphertext' => Crypt::encryptString('changed')]);
        $this->assertSame(1, Artisan::call('ingress:import-snapshot', ['event_id' => '10']));
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('node_import_ids', 0);
    }

    public function test_newer_snapshot_cannot_replace_an_imported_event(): void
    {
        $this->stage($this->payload());
        $eventId = app(ImportReceivedSnapshot::class)->execute(10);
        $this->stage($this->payload(10, 2));
        try {
            app(ImportReceivedSnapshot::class)->execute(10);
            $this->fail('A newer snapshot replaced the imported event.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('node_event_imports', ['cloud_event_id' => 10, 'event_id' => $eventId, 'version' => 1]);
            $this->assertDatabaseCount('events', 1);
            $this->assertDatabaseCount('tickets', 1);
        }
    }
}
