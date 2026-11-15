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

    public function execute(string $json, bool $allowLegacy = false): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        $legacyKeys = ['schema_version', 'node_id', 'event_id', 'snapshot_id', 'version', 'snapshot_sha256',
            'recovery_id', 'issuance_id', 'staff_id', 'recovered_at'];
        $keys = [...$legacyKeys, 'identity_verified', 'reason'];
        if (! is_array($data) || array_is_list($data)) {
            $this->invalid();
        }
        if ($allowLegacy && ($data['schema_version'] ?? null) === 1) {
            if (! $this->hasExactKeys($data, $legacyKeys) || ! $this->hasValidBase($data)) {
                $this->invalid();
            }

            $data['schema_version'] = 2;
            $data['identity_verified'] = false;
            $data['reason'] = RecoverNodeWalkIn::LEGACY_REASON;

            return $data;
        }
        if (! $this->hasExactKeys($data, $keys) || ! $this->hasValidBase($data) || $data['schema_version'] !== 2
            || ! is_bool($data['identity_verified']) || ! is_string($data['reason'])
            || ($data['identity_verified'] && ! in_array($data['reason'], RecoverNodeWalkIn::REASONS, true))
            || (! $data['identity_verified'] && $data['reason'] !== RecoverNodeWalkIn::LEGACY_REASON)) {
            $this->invalid();
        }

        return $data;
    }

    private function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff($keys, array_keys($data)) === [];
    }

    private function hasValidBase(array $data): bool
    {
        return is_string($data['node_id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['node_id'])
            && is_int($data['event_id'] ?? null) && $data['event_id'] > 0
            && is_int($data['version'] ?? null) && $data['version'] > 0
            && is_int($data['recovery_id'] ?? null) && $data['recovery_id'] > 0
            && is_int($data['staff_id'] ?? null) && $data['staff_id'] > 0
            && is_string($data['snapshot_id'] ?? null) && Str::isUuid($data['snapshot_id'])
            && is_string($data['issuance_id'] ?? null) && Str::isUuid($data['issuance_id'])
            && is_string($data['snapshot_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $data['snapshot_sha256'])
            && is_string($data['recovered_at'] ?? null)
            && ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $data['recovered_at'])) !== false
            && $date->format('Y-m-d\TH:i:s.u\Z') === $data['recovered_at'];
    }
}
