<?php

namespace App\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ValidateAdmissionBatch
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid admission synchronization package.']);
    }

    private function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || array_is_list($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            $this->invalid();
        }

        return $value;
    }

    public function execute(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        $data = $this->object($data, ['schema_version', 'batch_id', 'node_id', 'event_id', 'snapshot_id', 'version', 'snapshot_sha256', 'admissions']);
        if (! in_array($data['schema_version'], [1, 2, 3], true) || ! is_string($data['batch_id']) || ! Str::isUuid($data['batch_id'])
            || ! is_string($data['snapshot_id']) || ! Str::isUuid($data['snapshot_id'])
            || ! is_string($data['node_id']) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['node_id'])
            || ! is_int($data['event_id']) || $data['event_id'] < 1 || ! is_int($data['version']) || $data['version'] < 1
            || ! is_string($data['snapshot_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['snapshot_sha256'])
            || ! is_array($data['admissions']) || ! array_is_list($data['admissions']) || count($data['admissions']) < 1 || count($data['admissions']) > 100) {
            $this->invalid();
        }
        $previous = 0;
        $tickets = $scans = $directions = [];
        foreach ($data['admissions'] as $index => $item) {
            $keys = ['admission_id', 'ticket_id', 'gate_id', 'staff_id', 'scan_id', 'credential_version', 'admitted_at'];
            if ($data['schema_version'] >= 2) {
                $keys[] = 'direction';
            }
            if ($data['schema_version'] >= 3) {
                $keys[] = 'identity_check_confirmed';
            }
            $item = $this->object($item, $keys);
            $item['direction'] = $item['direction'] ?? 'entry';
            $item['identity_check_confirmed'] = $item['identity_check_confirmed'] ?? false;
            foreach (['admission_id', 'ticket_id', 'gate_id', 'staff_id', 'credential_version'] as $key) {
                if (! is_int($item[$key]) || $item[$key] < 1) {
                    $this->invalid();
                }
            }
            if ($item['admission_id'] <= $previous
                || ($data['schema_version'] === 1 && isset($tickets[$item['ticket_id']]))
                || ! is_string($item['scan_id']) || $item['scan_id'] === '' || mb_strlen($item['scan_id']) > 64
                || str_contains($item['scan_id'], "\0") || isset($scans[$item['scan_id']])
                || ! in_array($item['direction'], ['entry', 'exit'], true)
                || ! is_bool($item['identity_check_confirmed'])
                || (isset($directions[$item['ticket_id']]) && $directions[$item['ticket_id']] === $item['direction'])
                || ! is_string($item['admitted_at'])
                || ! ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $item['admitted_at']))
                || $date->format('Y-m-d\TH:i:s.u\Z') !== $item['admitted_at']) {
                $this->invalid();
            }
            $previous = $item['admission_id'];
            $tickets[$item['ticket_id']] = true;
            $scans[$item['scan_id']] = true;
            $directions[$item['ticket_id']] = $item['direction'];
            $data['admissions'][$index] = $item;
        }

        return $data;
    }
}
