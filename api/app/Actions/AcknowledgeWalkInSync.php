<?php

namespace App\Actions;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcknowledgeWalkInSync
{
    public function __construct(private BindRuntimeDatabase $runtime, private AdmissionSyncEnvelope $envelope,
        private ValidateWalkInSync $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid walk-in synchronization receipt.']);
    }

    public function execute(int $cloudEventId, string $issuanceId, int $registrationId, int $ticketId, string $receipt): void
    {
        $secret = config('ingress.node_sync_key');
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1 || $registrationId < 1 || $ticketId < 1
            || ! is_string($secret) || ! preg_match('/\A[a-f0-9]{64}\z/', $receipt)) {
            $this->invalid();
        }
        $this->runtime->execute();
        DB::transaction(function () use ($cloudEventId, $issuanceId, $registrationId, $ticketId, $receipt, $secret) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
            $row = $import ? DB::table('node_walk_in_issuances as w')
                ->join('registrations as r', 'r.id', '=', 'w.registration_id')
                ->join('tickets as t', 't.id', '=', 'w.ticket_id')
                ->where('w.event_id', $import->event_id)->where('w.issuance_id', $issuanceId)->whereNull('w.synced_at')
                ->first(['w.*', 'r.event_id as registration_event_id', 'r.guest_name', 'r.guest_email', 'r.guest_student_id', 'r.walk_in_kind',
                    't.registration_id as ticket_registration_id', 't.public_id', 't.qr_hash']) : null;
            if (! $row || ! is_string($row->sync_package_ciphertext) || ! is_string($row->sync_payload_hash)
                || ! hash_equals($this->envelope->walkInReceipt($issuanceId, $row->sync_payload_hash, $registrationId, $ticketId, $secret), $receipt)) {
                $this->invalid();
            }
            $payload = $this->envelope->open(Crypt::decryptString($row->sync_package_ciphertext), $secret);
            $data = $this->validator->execute($payload);
            if (! hash_equals($row->sync_payload_hash, hash('sha256', $payload)) || $data['issuance_id'] !== $issuanceId
                || $data['node_id'] !== config('ingress.node_id') || $data['event_id'] !== $cloudEventId
                || $data['snapshot_id'] !== $import->snapshot_id || $data['version'] !== (int) $import->version
                || ! hash_equals($data['snapshot_sha256'], $import->sha256)
                || (int) $row->registration_event_id !== (int) $import->event_id || (int) $row->ticket_registration_id !== (int) $row->registration_id
                || $data['name'] !== $row->guest_name || $data['email'] !== $row->guest_email || $data['kind'] !== $row->walk_in_kind
                || $data['student_id'] !== $row->guest_student_id || $data['public_id'] !== $row->public_id || ! hash_equals($data['qr_hash'], $row->qr_hash)) {
                $this->invalid();
            }
            DB::table('node_import_ids')->insert([
                ['kind' => 'registration', 'cloud_id' => $registrationId, 'local_id' => $row->registration_id, 'role_known' => false],
                ['kind' => 'ticket', 'cloud_id' => $ticketId, 'local_id' => $row->ticket_id, 'role_known' => false],
            ]);
            DB::table('node_walk_in_issuances')->where('id', $row->id)->update([
                'synced_at' => now(), 'sync_package_ciphertext' => null, 'updated_at' => now(),
            ]);
        });
    }
}
