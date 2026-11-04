<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcknowledgeAdmissionBatch
{
    public function __construct(private BindRuntimeDatabase $runtime, private AdmissionSyncEnvelope $envelope,
        private ValidateAdmissionBatch $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid admission synchronization receipt.']);
    }

    public function execute(int $cloudEventId, string $batchId, string $receipt): void
    {
        $secret = config('ingress.node_sync_key');
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1 || ! is_string($secret)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $receipt)) {
            $this->invalid();
        }
        $this->runtime->execute();
        DB::transaction(function () use ($cloudEventId, $batchId, $receipt, $secret) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
            $pending = $import ? DB::table('node_admission_batches')->where('event_id', $import->event_id)->first() : null;
            if (! $pending || $pending->batch_id !== $batchId
                || ! hash_equals($this->envelope->receipt($batchId, $pending->payload_hash, $secret), $receipt)) {
                $this->invalid();
            }
            $package = Crypt::decryptString($pending->package_ciphertext);
            $payload = $this->envelope->open($package, $secret);
            $data = $this->validator->execute($payload);
            if (! hash_equals($pending->payload_hash, hash('sha256', $payload)) || $data['batch_id'] !== $batchId
                || $data['node_id'] !== config('ingress.node_id') || $data['event_id'] !== $cloudEventId
                || $data['snapshot_id'] !== $import->snapshot_id || $data['version'] !== (int) $import->version
                || ! hash_equals($data['snapshot_sha256'], $import->sha256)) {
                $this->invalid();
            }
            foreach ($data['admissions'] as $item) {
                $row = DB::table('admissions as a')
                    ->join('tickets as t', 't.id', '=', 'a.ticket_id')
                    ->join('registrations as r', 'r.id', '=', 't.registration_id')
                    ->join('access_points as g', 'g.id', '=', 'a.access_point_id')
                    ->leftJoin('node_import_ids as ti', fn ($join) => $join->on('ti.local_id', '=', 'a.ticket_id')->where('ti.kind', '=', 'ticket'))
                    ->leftJoin('node_import_ids as gi', fn ($join) => $join->on('gi.local_id', '=', 'a.access_point_id')->where('gi.kind', '=', 'gate'))
                    ->leftJoin('node_import_ids as si', fn ($join) => $join->on('si.local_id', '=', 'a.scanned_by_user_id')->where('si.kind', '=', 'user'))
                    ->where('a.id', $item['admission_id'])->where('r.event_id', $import->event_id)
                    ->first(['a.client_scan_id', 'a.credential_version', 'a.direction', 'a.identity_check_confirmed', 'a.admitted_at', 'g.event_id as gate_event_id',
                        'ti.cloud_id as cloud_ticket_id', 'gi.cloud_id as cloud_gate_id', 'si.cloud_id as cloud_staff_id']);
                if (! $row || (int) $row->gate_event_id !== (int) $import->event_id
                    || (int) $row->cloud_ticket_id !== $item['ticket_id'] || (int) $row->cloud_gate_id !== $item['gate_id']
                    || (int) $row->cloud_staff_id !== $item['staff_id'] || $row->client_scan_id !== $item['scan_id']
                    || (int) $row->credential_version !== $item['credential_version'] || $row->direction !== $item['direction']
                    || (bool) $row->identity_check_confirmed !== $item['identity_check_confirmed']
                    || ! CarbonImmutable::parse($row->admitted_at, 'UTC')->equalTo(CarbonImmutable::parse($item['admitted_at']))) {
                    $this->invalid();
                }
                DB::table('node_synced_admissions')->insertOrIgnore([
                    'admission_id' => $item['admission_id'], 'batch_id' => $batchId, 'synced_at' => now(),
                ]);
                $marked = DB::table('node_synced_admissions')->where('admission_id', $item['admission_id'])->first();
                if (! $marked || $marked->batch_id !== $batchId) {
                    $this->invalid();
                }
            }
            DB::table('node_admission_batches')->where('event_id', $import->event_id)->delete();
        });
    }
}
