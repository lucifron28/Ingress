<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareWalkInSync
{
    public function __construct(private BindRuntimeDatabase $runtime, private AuthorityGrant $grants,
        private AdmissionSyncEnvelope $envelope, private ValidateWalkInSync $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Walk-in synchronization cannot be prepared.']);
    }

    public function execute(int $cloudEventId): ?array
    {
        $nodeId = config('ingress.node_id');
        $secret = config('ingress.node_sync_key');
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1 || ! is_string($nodeId) || ! is_string($secret)) {
            $this->invalid();
        }
        $this->runtime->execute();

        return DB::transaction(function () use ($cloudEventId, $nodeId, $secret) {
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
            $row = DB::table('node_walk_in_issuances as w')
                ->join('registrations as r', 'r.id', '=', 'w.registration_id')
                ->join('tickets as t', 't.id', '=', 'w.ticket_id')
                ->leftJoin('node_import_ids as s', fn ($join) => $join->on('s.local_id', '=', 'w.issued_by_user_id')->where('s.kind', '=', 'user'))
                ->where('w.event_id', $import->event_id)->whereNull('w.synced_at')->orderBy('w.id')
                ->first(['w.*', 'r.event_id as registration_event_id', 'r.attendee_type', 'r.walk_in_kind', 'r.guest_name', 'r.guest_email',
                    'r.guest_student_id', 't.registration_id as ticket_registration_id', 't.public_id', 't.qr_hash', 't.issued_at',
                    't.status as ticket_status', 't.credential_version', 's.cloud_id as cloud_staff_id']);
            if (! $row) {
                return null;
            }
            if ((int) $row->registration_event_id !== (int) $import->event_id || (int) $row->ticket_registration_id !== (int) $row->registration_id
                || $row->attendee_type !== 'walk_in' || $row->ticket_status !== 'active' || (int) $row->credential_version !== 1 || ! $row->cloud_staff_id) {
                $this->invalid();
            }
            if ($row->sync_package_ciphertext !== null) {
                $package = Crypt::decryptString($row->sync_package_ciphertext);
                $payload = $this->envelope->open($package, $secret);
                $data = $this->validator->execute($payload);
                if (! is_string($row->sync_payload_hash) || ! hash_equals($row->sync_payload_hash, hash('sha256', $payload))
                    || $data['issuance_id'] !== $row->issuance_id || $data['node_id'] !== $nodeId || $data['event_id'] !== $cloudEventId
                    || $data['snapshot_id'] !== $import->snapshot_id || $data['version'] !== (int) $import->version
                    || ! hash_equals($data['snapshot_sha256'], $import->sha256)) {
                    $this->invalid();
                }

                return ['issuance_id' => $row->issuance_id, 'package' => $package];
            }
            $data = ['schema_version' => 1, 'node_id' => $nodeId, 'event_id' => $cloudEventId,
                'snapshot_id' => $import->snapshot_id, 'version' => (int) $import->version, 'snapshot_sha256' => $import->sha256,
                'issuance_id' => $row->issuance_id, 'staff_id' => (int) $row->cloud_staff_id, 'name' => $row->guest_name,
                'email' => $row->guest_email, 'kind' => $row->walk_in_kind, 'student_id' => $row->guest_student_id,
                'public_id' => $row->public_id, 'qr_hash' => $row->qr_hash,
                'issued_at' => CarbonImmutable::parse($row->issued_at, 'UTC')->utc()->toISOString()];
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
            $this->validator->execute($payload);
            $package = $this->envelope->seal($payload, $secret);
            DB::table('node_walk_in_issuances')->where('id', $row->id)->update([
                'sync_package_ciphertext' => Crypt::encryptString($package), 'sync_payload_hash' => hash('sha256', $payload), 'updated_at' => now(),
            ]);

            return ['issuance_id' => $row->issuance_id, 'package' => $package];
        });
    }
}
