<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncWalkInRecovery
{
    public function __construct(private BindRuntimeDatabase $runtime, private AuthorityGrant $grants,
        private AdmissionSyncEnvelope $envelope, private ValidateWalkInRecoverySync $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Walk-in recovery synchronization failed.']);
    }

    private function imported(int $cloudEventId): object
    {
        $nodeId = config('ingress.node_id');
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1 || ! is_string($nodeId) || ! is_string(config('ingress.node_sync_key'))) {
            $this->invalid();
        }
        DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
        $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
        $head = DB::table('received_event_heads')->where('event_id', $cloudEventId)->lockForUpdate()->first();
        $authority = $import ? DB::table('node_event_authorities')->where('event_id', $import->event_id)->first() : null;
        if (! $import || ! $head || ! $authority || (int) $head->version !== (int) $import->version
            || (int) $authority->cloud_event_id !== $cloudEventId || $authority->snapshot_id !== $import->snapshot_id
            || (int) $authority->version !== (int) $import->version || ! hash_equals($authority->sha256, $import->sha256)
            || $this->grants->verify($authority->grant) !== ['protocol' => 1, 'node_id' => $nodeId, 'snapshot_id' => $import->snapshot_id,
                'event_id' => $cloudEventId, 'version' => (int) $import->version, 'sha256' => $import->sha256]) {
            $this->invalid();
        }

        return $import;
    }

    private function pending(object $import, ?int $recoveryId = null): ?object
    {
        return DB::table('node_walk_in_recoveries as r')
            ->join('node_walk_in_issuances as w', 'w.id', '=', 'r.issuance_id')
            ->leftJoin('node_import_ids as s', fn ($join) => $join->on('s.local_id', '=', 'r.recovered_by_user_id')->where('s.kind', '=', 'user'))
            ->where('w.event_id', $import->event_id)->whereNotNull('w.synced_at')->whereNull('r.synced_at')
            ->when($recoveryId !== null, fn ($query) => $query->where('r.id', $recoveryId))
            ->orderBy('r.id')->first(['r.*', 'w.issuance_id as issuance_uuid', 's.cloud_id as cloud_staff_id']);
    }

    private function payload(object $row, object $import): array
    {
        if (! $row->cloud_staff_id) {
            $this->invalid();
        }

        return ['schema_version' => 2, 'node_id' => config('ingress.node_id'), 'event_id' => (int) $import->cloud_event_id,
            'snapshot_id' => $import->snapshot_id, 'version' => (int) $import->version, 'snapshot_sha256' => $import->sha256,
            'recovery_id' => (int) $row->id, 'issuance_id' => $row->issuance_uuid, 'staff_id' => (int) $row->cloud_staff_id,
            'recovered_at' => CarbonImmutable::parse($row->recovered_at, 'UTC')->utc()->toISOString(),
            'identity_verified' => (bool) $row->identity_verified, 'reason' => $row->reason];
    }

    public function prepare(int $cloudEventId): ?array
    {
        $this->runtime->execute();

        return DB::transaction(function () use ($cloudEventId) {
            $import = $this->imported($cloudEventId);
            $row = $this->pending($import);
            if (! $row) {
                return null;
            }
            $data = $this->payload($row, $import);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            $this->validator->execute($json);
            $hash = hash('sha256', $json);
            $secret = config('ingress.node_sync_key');
            if ($row->sync_package_ciphertext !== null) {
                $package = Crypt::decryptString($row->sync_package_ciphertext);
                $saved = $this->envelope->open($package, $secret);
                $savedData = $this->validator->execute($saved, allowLegacy: true);
                $savedPayloadHash = hash('sha256', $saved);
                $savedVersion = json_decode($saved, true, 8, JSON_THROW_ON_ERROR)['schema_version'] ?? null;
                if (! is_string($row->sync_payload_hash) || ! hash_equals($row->sync_payload_hash, $savedPayloadHash)
                    || $savedData !== $data || ($savedVersion !== 1 && ! hash_equals($hash, $savedPayloadHash))) {
                    $this->invalid();
                }
                if ($savedVersion === 1) {
                    $package = $this->envelope->seal($json, $secret);
                    DB::table('node_walk_in_recoveries')->where('id', $row->id)->update([
                        'sync_package_ciphertext' => Crypt::encryptString($package), 'sync_payload_hash' => $hash,
                    ]);
                }
            } else {
                $package = $this->envelope->seal($json, $secret);
                DB::table('node_walk_in_recoveries')->where('id', $row->id)->update([
                    'sync_package_ciphertext' => Crypt::encryptString($package), 'sync_payload_hash' => $hash,
                ]);
            }

            return ['recovery_id' => (int) $row->id, 'package' => $package];
        });
    }

    public function acknowledge(int $cloudEventId, int $recoveryId, string $receipt): void
    {
        if ($recoveryId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $receipt)) {
            $this->invalid();
        }
        $this->runtime->execute();
        DB::transaction(function () use ($cloudEventId, $recoveryId, $receipt) {
            $import = $this->imported($cloudEventId);
            $row = $this->pending($import, $recoveryId);
            if (! $row || ! is_string($row->sync_package_ciphertext) || ! is_string($row->sync_payload_hash)) {
                $this->invalid();
            }
            $secret = config('ingress.node_sync_key');
            $json = $this->envelope->open(Crypt::decryptString($row->sync_package_ciphertext), $secret);
            if (! hash_equals($row->sync_payload_hash, hash('sha256', $json))
                || $this->validator->execute($json) !== $this->payload($row, $import)
                || ! hash_equals($this->envelope->walkInRecoveryReceipt($recoveryId, $row->sync_payload_hash, $secret), $receipt)) {
                $this->invalid();
            }
            DB::table('node_walk_in_recoveries')->where('id', $recoveryId)->update([
                'synced_at' => now(), 'sync_package_ciphertext' => null,
            ]);
        });
    }
}
