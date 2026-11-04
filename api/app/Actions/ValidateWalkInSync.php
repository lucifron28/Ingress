<?php

namespace App\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ValidateWalkInSync
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid walk-in synchronization package.']);
    }

    public function execute(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        $keys = ['schema_version', 'node_id', 'event_id', 'snapshot_id', 'version', 'snapshot_sha256', 'issuance_id',
            'staff_id', 'name', 'email', 'kind', 'student_id', 'public_id', 'qr_hash', 'issued_at'];
        if (! is_array($data) || array_is_list($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data))
            || $data['schema_version'] !== 1 || ! is_string($data['node_id']) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['node_id'])
            || ! is_int($data['event_id']) || $data['event_id'] < 1 || ! is_int($data['version']) || $data['version'] < 1
            || ! is_int($data['staff_id']) || $data['staff_id'] < 1
            || ! is_string($data['snapshot_id']) || ! Str::isUuid($data['snapshot_id'])
            || ! is_string($data['issuance_id']) || ! Str::isUuid($data['issuance_id'])
            || ! is_string($data['snapshot_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['snapshot_sha256'])
            || ! is_string($data['name']) || $data['name'] === '' || trim($data['name']) !== $data['name'] || mb_strlen($data['name']) > 255 || str_contains($data['name'], "\0")
            || ! is_string($data['email']) || strlen($data['email']) > 255 || Str::lower(trim($data['email'])) !== $data['email'] || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)
            || ! in_array($data['kind'], ['student', 'guest', 'alumni'], true)
            || ($data['kind'] === 'student' && (! is_string($data['student_id']) || $data['student_id'] === '' || trim($data['student_id']) !== $data['student_id'] || mb_strlen($data['student_id']) > 255 || str_contains($data['student_id'], "\0")))
            || ($data['kind'] !== 'student' && $data['student_id'] !== null)
            || ! is_string($data['public_id']) || ! preg_match('/\AING-[A-Z0-9]{10}\z/', $data['public_id'])
            || ! is_string($data['qr_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['qr_hash'])
            || ! is_string($data['issued_at']) || ! ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $data['issued_at']))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $data['issued_at']) {
            $this->invalid();
        }

        return $data;
    }
}
