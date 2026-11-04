<?php

namespace Tests\Unit;

use App\Actions\ValidateAdmissionBatch;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdmissionMovementBatchValidationTest extends TestCase
{
    private function payload(int $schemaVersion, array $admissions): array
    {
        return [
            'schema_version' => $schemaVersion,
            'batch_id' => (string) Str::uuid(),
            'node_id' => 'node-1',
            'event_id' => 1,
            'snapshot_id' => (string) Str::uuid(),
            'version' => 1,
            'snapshot_sha256' => str_repeat('a', 64),
            'admissions' => $admissions,
        ];
    }

    private function movement(int $id, string $direction): array
    {
        return [
            'admission_id' => $id,
            'ticket_id' => 10,
            'gate_id' => 20,
            'staff_id' => 30,
            'scan_id' => "scan-{$id}",
            'credential_version' => 1,
            'direction' => $direction,
            'admitted_at' => sprintf('2026-11-04T12:00:%02d.000000Z', $id),
        ];
    }

    public function test_version_two_accepts_alternating_movements_for_one_ticket(): void
    {
        $data = $this->payload(2, [
            $this->movement(1, 'entry'),
            $this->movement(2, 'exit'),
            $this->movement(3, 'entry'),
        ]);

        $this->assertSame($data, app(ValidateAdmissionBatch::class)->execute(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function test_version_one_normalizes_legacy_admissions_to_entries(): void
    {
        $data = $this->payload(1, [$this->movement(1, 'entry')]);
        unset($data['admissions'][0]['direction']);

        $validated = app(ValidateAdmissionBatch::class)->execute(json_encode($data, JSON_THROW_ON_ERROR));

        $this->assertSame('entry', $validated['admissions'][0]['direction']);
    }

    public function test_version_two_rejects_two_consecutive_movements_in_the_same_direction(): void
    {
        $data = $this->payload(2, [
            $this->movement(1, 'entry'),
            $this->movement(2, 'entry'),
        ]);

        $this->expectException(ValidationException::class);
        app(ValidateAdmissionBatch::class)->execute(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
