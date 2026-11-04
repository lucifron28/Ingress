<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveWalkInRecovery
{
    public function __construct(private AdmissionSyncEnvelope $envelope, private ValidateWalkInRecoverySync $validator,
        private ValidateSnapshotPayload $snapshots) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Walk-in recovery synchronization was rejected.']);
    }

    public function execute(string $nodeId, string $package): array
    {
        if (config('ingress.mode') !== 'cloud' || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)) {
            $this->invalid();
        }
        $node = DB::table('event_nodes')->where('id', $nodeId)->first();
        if (! $node || $node->revoked_at !== null || $node->key_ciphertext === null) {
            $this->invalid();
        }
        $secret = Crypt::decryptString($node->key_ciphertext);
        $payload = $this->envelope->open($package, $secret);
        $data = $this->validator->execute($payload);
        if ($data['node_id'] !== $nodeId) {
            $this->invalid();
        }
        $hash = hash('sha256', $payload);
        try {
            return DB::transaction(function () use ($nodeId, $secret, $data, $hash) {
                $event = DB::table('events')->where('id', $data['event_id'])->lockForUpdate()->first();
                $node = DB::table('event_nodes')->where('id', $nodeId)->lockForUpdate()->first();
                $authority = DB::table('cloud_event_authorities')->where('event_id', $data['event_id'])->first();
                $snapshot = DB::table('event_snapshots')->where('id', $data['snapshot_id'])->first();
                if (! $event || $event->status !== 'published' || $event->snapshot_prepared_at === null
                    || ! $node || $node->revoked_at !== null || $node->key_ciphertext === null
                    || ! hash_equals($secret, Crypt::decryptString($node->key_ciphertext))
                    || ! $authority || $authority->node_id !== $nodeId || $authority->snapshot_id !== $data['snapshot_id']
                    || ! $snapshot || (int) $snapshot->event_id !== $data['event_id'] || $snapshot->target_node_id !== $nodeId
                    || (int) $snapshot->version !== $data['version'] || (int) $event->snapshot_version !== $data['version']
                    || $snapshot->invalidated_at !== null || ! hash_equals($snapshot->sha256, $data['snapshot_sha256'])) {
                    $this->invalid();
                }
                $existing = DB::table('cloud_walk_in_recoveries')->where('node_id', $nodeId)->where('node_recovery_id', $data['recovery_id'])->first();
                if ($existing) {
                    if ((int) $existing->event_id !== $data['event_id'] || ! hash_equals($existing->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return $this->receipt($data['recovery_id'], $hash, $secret);
                }
                if (! DB::table('cloud_walk_in_receipts')->where('node_id', $nodeId)->where('event_id', $data['event_id'])
                    ->where('issuance_id', $data['issuance_id'])->exists()) {
                    $this->invalid();
                }
                $json = Crypt::decryptString($snapshot->payload_ciphertext);
                if (! hash_equals($snapshot->sha256, hash('sha256', $json))) {
                    $this->invalid();
                }
                $roster = $this->snapshots->execute($json);
                if ($roster['snapshot_id'] !== $data['snapshot_id'] || $roster['version'] !== $data['version']
                    || $roster['event']['id'] !== $data['event_id']) {
                    $this->invalid();
                }
                $assigned = false;
                foreach ($roster['staff'] as $assignment) {
                    $assigned = $assigned || $assignment['user']['id'] === $data['staff_id'];
                }
                if (! $assigned || ! DB::table('users')->where('id', $data['staff_id'])->exists()) {
                    $this->invalid();
                }
                DB::table('cloud_walk_in_recoveries')->insert([
                    'node_id' => $nodeId, 'node_recovery_id' => $data['recovery_id'], 'issuance_id' => $data['issuance_id'],
                    'event_id' => $data['event_id'], 'recovered_by_user_id' => $data['staff_id'],
                    'recovered_at' => CarbonImmutable::parse($data['recovered_at'])->utc(), 'payload_hash' => $hash, 'received_at' => now(),
                ]);

                return $this->receipt($data['recovery_id'], $hash, $secret);
            });
        } catch (UniqueConstraintViolationException) {
            $this->invalid();
        }
    }

    private function receipt(int $recoveryId, string $hash, string $secret): array
    {
        return ['recovery_id' => $recoveryId, 'receipt' => $this->envelope->walkInRecoveryReceipt($recoveryId, $hash, $secret)];
    }
}
