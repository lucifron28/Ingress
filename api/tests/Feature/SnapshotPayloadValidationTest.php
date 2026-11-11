<?php

namespace Tests\Feature;

use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Actions\ValidateSnapshotPayload;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SnapshotPayloadValidationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $date = '2026-01-01T00:00:00.000000Z';

        return [
            'schema_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'version' => 1,
            'event' => [
                'id' => 1, 'title' => 'Event', 'slug' => 'event', 'description' => null, 'capacity' => 2,
                'status' => 'published', 'starts_at' => $date, 'ends_at' => '2026-01-02T00:00:00.000000Z',
                'registration_opens_at' => $date, 'registration_closes_at' => '2026-01-02T00:00:00.000000Z',
                'registration_closed_at' => null, 'venue' => ['id' => 1, 'name' => 'Hall', 'code' => 'H', 'location' => null],
                'organizer' => ['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [['id' => 1, 'name' => 'Gate', 'code' => 'G', 'is_active' => true]],
            'staff' => [['id' => 1, 'access_point_id' => 1, 'user' => ['id' => 2, 'name' => 'Staff', 'email' => 'staff@example.test', 'role' => 'staff']]],
            'registrations' => [[
                'id' => 1, 'status' => 'confirmed', 'created_at' => $date, 'cancelled_at' => null,
                'attendee' => ['id' => 3, 'name' => 'Attendee', 'email' => 'attendee@example.test', 'student_id' => null],
                'ticket' => ['id' => 1, 'public_id' => 'ticket-1', 'qr_hash' => str_repeat('a', 64), 'status' => 'active', 'credential_version' => 1, 'issued_at' => $date, 'revoked_at' => null],
            ]],
        ];
    }

    public function test_rejects_invalid_schema_and_references(): void
    {
        $valid = $this->payload();
        $this->assertSame($valid, app(ValidateSnapshotPayload::class)->execute(json_encode($valid, JSON_THROW_ON_ERROR)));
        $cases = [];
        $bad = $valid;
        $bad['event']['organizer']['password_hash'] = 'secret';
        $cases[] = $bad;
        $bad = $valid;
        $bad['registrations'][0]['ticket']['qr_secret'] = 'secret';
        $cases[] = $bad;
        $bad = $valid;
        unset($bad['event']['capacity']);
        $cases[] = $bad;
        $bad = $valid;
        $bad['staff'][0]['access_point_id'] = 99;
        $cases[] = $bad;
        $bad = $valid;
        $bad['staff'][0]['user']['id'] = 1;
        $cases[] = $bad;
        $bad = $valid;
        $bad['registrations'][0]['ticket']['qr_hash'] = 'bad';
        $cases[] = $bad;
        $bad = $valid;
        $bad['registrations'][0]['ticket']['credential_version'] = 0;
        $cases[] = $bad;
        $bad = $valid;
        $bad['registrations'][0]['ticket'] = null;
        $cases[] = $bad;
        $bad = $valid;
        $bad['registrations'][0]['status'] = 'unknown';
        $cases[] = $bad;
        $bad = $valid;
        $bad['event']['ends_at'] = '2026-02-30T00:00:00.000000Z';
        $cases[] = $bad;
        $bad = $valid;
        $bad['access_points'] = ['gate' => $bad['access_points'][0]];
        $cases[] = $bad;
        $bad = $valid;
        $bad['access_points'][] = $bad['access_points'][0];
        $cases[] = $bad;
        foreach (['id', 'public_id', 'qr_hash'] as $duplicate) {
            $bad = $valid;
            $other = $bad['registrations'][0];
            $other['id'] = 2;
            $other['attendee']['id'] = 4;
            $other['ticket']['id'] = 2;
            $other['ticket']['public_id'] = 'ticket-2';
            $other['ticket']['qr_hash'] = str_repeat('b', 64);
            $other['ticket'][$duplicate] = $bad['registrations'][0]['ticket'][$duplicate];
            $bad['registrations'][] = $other;
            $cases[] = $bad;
        }
        $bad = $valid;
        $bad['event']['capacity'] = 1;
        $other = $bad['registrations'][0];
        $other['id'] = 2;
        $other['attendee']['id'] = 4;
        $other['ticket']['id'] = 2;
        $other['ticket']['public_id'] = 'ticket-2';
        $other['ticket']['qr_hash'] = str_repeat('b', 64);
        $bad['registrations'][] = $other;
        $cases[] = $bad;
        foreach ($cases as $index => $payload) {
            try {
                app(SnapshotEnvelope::class)->seal('node-1', base64_encode(random_bytes(32)), json_encode($payload, JSON_THROW_ON_ERROR));
                $this->fail("Invalid snapshot case {$index} was accepted.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_schema_three_requires_an_enabled_reentry_policy(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = 3;
        $payload['event']['re_entry_allowed'] = true;
        $payload['registrations'][0]['attendee']['kind'] = 'student';
        $this->assertSame($payload, app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR)));

        unset($payload['event']['re_entry_allowed']);
        try {
            app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR));
            $this->fail('A schema three snapshot without re-entry policy was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $payload['event']['re_entry_allowed'] = false;
        try {
            app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR));
            $this->fail('A schema three snapshot with re-entry disabled was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_schema_four_carries_both_reentry_and_identity_policies(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = 4;
        $payload['event']['re_entry_allowed'] = false;
        $payload['event']['identity_verification_level'] = 'id_check';
        $payload['registrations'][0]['attendee']['kind'] = 'student';

        $this->assertSame($payload, app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR)));

        $payload['event']['identity_verification_level'] = 'photo';
        $this->expectException(ValidationException::class);
        app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_schema_four_allows_guest_contact_email_to_match_a_student_account(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = 4;
        $payload['event']['re_entry_allowed'] = false;
        $payload['event']['identity_verification_level'] = 'standard';
        $payload['registrations'][0]['attendee'] = [
            'id' => 3,
            'name' => 'Student Attendee',
            'email' => 'shared@example.test',
            'student_id' => null,
            'kind' => 'student',
        ];
        $guest = $payload['registrations'][0];
        $guest['id'] = 2;
        $guest['attendee'] = [
            'id' => null,
            'name' => 'Guest Attendee',
            'email' => 'shared@example.test',
            'student_id' => null,
            'kind' => 'guest',
        ];
        $guest['ticket']['id'] = 2;
        $guest['ticket']['public_id'] = 'ticket-2';
        $guest['ticket']['qr_hash'] = str_repeat('b', 64);
        $payload['registrations'][] = $guest;

        $this->assertSame($payload, app(ValidateSnapshotPayload::class)->execute(json_encode($payload, JSON_THROW_ON_ERROR)));

        $duplicateGuests = $payload;
        $duplicateGuests['registrations'][0]['attendee'] = [
            'id' => null,
            'name' => 'First Guest',
            'email' => 'shared@example.test',
            'student_id' => null,
            'kind' => 'guest',
        ];
        try {
            app(ValidateSnapshotPayload::class)->execute(json_encode($duplicateGuests, JSON_THROW_ON_ERROR));
            $this->fail('Two accountless attendees with the same event contact email were accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_authenticated_malformed_payload_is_not_staged_or_acknowledged(): void
    {
        $payload = $this->payload();
        $payload['registrations'][0]['ticket']['qr_secret'] = 'secret';
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret = base64_encode(random_bytes(32));
        $metadata = ['node_id' => 'node-1', 'snapshot_id' => $payload['snapshot_id'], 'event_id' => 1, 'version' => 1, 'sha256' => hash('sha256', $json)];
        $encrypter = new Encrypter(hash_hkdf('sha256', base64_decode($secret), 32, 'ingress snapshot encryption v1'), 'AES-256-CBC');
        $package = $encrypter->encryptString(json_encode(['protocol' => 1, 'metadata' => $metadata, 'payload' => $json], JSON_THROW_ON_ERROR));
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
        try {
            app(ReceiveSnapshot::class)->execute($package);
            $this->fail('Malformed authenticated snapshot was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('received_snapshots', 0);
            $this->assertDatabaseCount('received_event_heads', 0);
        }
    }
}
