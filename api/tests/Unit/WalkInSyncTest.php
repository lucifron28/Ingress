<?php

namespace Tests\Unit;

use App\Actions\AdmissionSyncEnvelope;
use App\Actions\ValidateWalkInSync;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalkInSyncTest extends TestCase
{
    public function test_walk_in_payload_and_receipt_bind_identity_and_cloud_ids(): void
    {
        $data = ['schema_version' => 1, 'node_id' => 'node-1', 'event_id' => 1,
            'snapshot_id' => (string) Str::uuid(), 'version' => 1, 'snapshot_sha256' => str_repeat('a', 64),
            'issuance_id' => (string) Str::uuid(), 'staff_id' => 2, 'name' => 'Guest', 'email' => 'guest@example.test',
            'kind' => 'guest', 'student_id' => null, 'public_id' => 'ING-ABCDEFGHIJ', 'qr_hash' => str_repeat('b', 64),
            'issued_at' => '2026-11-04T12:00:00.000000Z'];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertSame($data, app(ValidateWalkInSync::class)->execute($json));
        $secret = base64_encode(random_bytes(32));
        $envelope = app(AdmissionSyncEnvelope::class);
        $hash = hash('sha256', $json);
        $receipt = $envelope->walkInReceipt($data['issuance_id'], $hash, 20, 30, $secret);
        $this->assertNotSame($receipt, $envelope->walkInReceipt($data['issuance_id'], $hash, 20, 31, $secret));
        $this->assertNotSame($receipt, $envelope->walkInReceipt($data['issuance_id'], str_repeat('c', 64), 20, 30, $secret));

        $data['kind'] = 'student';
        $this->expectException(ValidationException::class);
        app(ValidateWalkInSync::class)->execute(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
