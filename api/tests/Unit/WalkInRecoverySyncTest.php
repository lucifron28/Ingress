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
            'schema_version' => 2, 'node_id' => 'node-1', 'event_id' => 1,
            'snapshot_id' => (string) Str::uuid(), 'version' => 1, 'snapshot_sha256' => str_repeat('a', 64),
            'recovery_id' => 42, 'issuance_id' => (string) Str::uuid(), 'staff_id' => 2,
            'recovered_at' => '2026-11-04T12:00:00.000000Z',
            'identity_verified' => true, 'reason' => 'lost',
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
        $this->assertRejected($data);
        $data['staff_id'] = 2;
        $data['reason'] = 'unapproved';
        $this->assertRejected($data);
        $data['reason'] = 'lost';
        $data['identity_verified'] = false;
        $this->assertRejected($data);
    }

    public function test_legacy_recovery_package_is_accepted_only_for_local_upgrade(): void
    {
        $legacy = [
            'schema_version' => 1, 'node_id' => 'node-1', 'event_id' => 1,
            'snapshot_id' => (string) Str::uuid(), 'version' => 1, 'snapshot_sha256' => str_repeat('a', 64),
            'recovery_id' => 42, 'issuance_id' => (string) Str::uuid(), 'staff_id' => 2,
            'recovered_at' => '2026-11-04T12:00:00.000000Z',
        ];
        $json = json_encode($legacy, JSON_THROW_ON_ERROR);
        try {
            app(ValidateWalkInRecoverySync::class)->execute($json);
            $this->fail('Cloud validation accepted a version 1 recovery package.');
        } catch (ValidationException) {
            $this->assertSame([
                ...$legacy, 'schema_version' => 2, 'identity_verified' => false, 'reason' => 'legacy',
            ], app(ValidateWalkInRecoverySync::class)->execute($json, allowLegacy: true));
        }
    }

    private function assertRejected(array $data): void
    {
        try {
            app(ValidateWalkInRecoverySync::class)->execute(json_encode($data, JSON_THROW_ON_ERROR));
            $this->fail('An invalid recovery package was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
