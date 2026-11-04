<?php

namespace Tests\Unit;

use App\Actions\AdmissionSyncEnvelope;
use App\Actions\ValidateWalkInRecoverySync;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalkInRecoverySyncTest extends TestCase
{
    public function test_recovery_payload_and_receipt_bind_the_record_and_content(): void
    {
        $data = [
            'schema_version' => 1, 'node_id' => 'node-1', 'event_id' => 1,
            'snapshot_id' => (string) Str::uuid(), 'version' => 1, 'snapshot_sha256' => str_repeat('a', 64),
            'recovery_id' => 42, 'issuance_id' => (string) Str::uuid(), 'staff_id' => 2,
            'recovered_at' => '2026-11-04T12:00:00.000000Z',
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertSame($data, app(ValidateWalkInRecoverySync::class)->execute($json));
        $secret = base64_encode(random_bytes(32));
        $envelope = app(AdmissionSyncEnvelope::class);
        $hash = hash('sha256', $json);
        $receipt = $envelope->walkInRecoveryReceipt(42, $hash, $secret);
        $this->assertNotSame($receipt, $envelope->walkInRecoveryReceipt(43, $hash, $secret));
        $this->assertNotSame($receipt, $envelope->walkInRecoveryReceipt(42, str_repeat('b', 64), $secret));
        $data['staff_id'] = 0;
        $this->expectException(ValidationException::class);
        app(ValidateWalkInRecoverySync::class)->execute(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
