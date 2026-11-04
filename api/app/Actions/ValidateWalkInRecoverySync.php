<?php

namespace App\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ValidateWalkInRecoverySync
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid walk-in recovery synchronization package.']);
    }

    public function execute(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        $keys = ['schema_version', 'node_id', 'event_id', 'snapshot_id', 'version', 'snapshot_sha256',
            'recovery_id', 'issuance_id', 'staff_id', 'recovered_at'];
        if (! is_array($data) || array_is_list($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data))
            || $data['schema_version'] !== 1 || ! is_string($data['node_id']) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['node_id'])
            || ! is_int($data['event_id']) || $data['event_id'] < 1 || ! is_int($data['version']) || $data['version'] < 1
            || ! is_int($data['recovery_id']) || $data['recovery_id'] < 1 || ! is_int($data['staff_id']) || $data['staff_id'] < 1
            || ! is_string($data['snapshot_id']) || ! Str::isUuid($data['snapshot_id'])
            || ! is_string($data['issuance_id']) || ! Str::isUuid($data['issuance_id'])
            || ! is_string($data['snapshot_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['snapshot_sha256'])
            || ! is_string($data['recovered_at']) || ! ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $data['recovered_at']))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $data['recovered_at']) {
            $this->invalid();
        }

        return $data;
    }
}
