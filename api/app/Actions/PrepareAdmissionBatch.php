<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrepareAdmissionBatch
{
    public function __construct(private BindRuntimeDatabase $runtime, private AuthorityGrant $grants,
        private AdmissionSyncEnvelope $envelope, private ValidateAdmissionBatch $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Node admission batch cannot be prepared.']);
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
            // ponytail: serialize batch preparation with node imports and credential changes; partition if it limits throughput.
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
            $head = DB::table('received_event_heads')->where('event_id', $cloudEventId)->lockForUpdate()->first();
            $authority = $import ? DB::table('node_event_authorities')->where('event_id', $import->event_id)->first() : null;
            if (! $import || ! $head || ! $authority || (int) $head->version !== (int) $import->version
                || (int) $authority->cloud_event_id !== $cloudEventId || $authority->snapshot_id !== $import->snapshot_id
                || (int) $authority->version !== (int) $import->version || ! hash_equals($authority->sha256, $import->sha256)) {
                $this->invalid();
            }
            $expected = ['protocol' => 1, 'node_id' => $nodeId, 'snapshot_id' => $import->snapshot_id,
                'event_id' => $cloudEventId, 'version' => (int) $import->version, 'sha256' => $import->sha256];
            if ($this->grants->verify($authority->grant) !== $expected) {
                $this->invalid();
            }
            $pending = DB::table('node_admission_batches')->where('event_id', $import->event_id)->first();
            if ($pending) {
                $package = Crypt::decryptString($pending->package_ciphertext);
                $payload = $this->envelope->open($package, $secret);
                $data = $this->validator->execute($payload);
                if ($data['batch_id'] !== $pending->batch_id || ! hash_equals($pending->payload_hash, hash('sha256', $payload))
                    || $data['node_id'] !== $nodeId || $data['event_id'] !== $cloudEventId || $data['snapshot_id'] !== $import->snapshot_id
                    || $data['version'] !== (int) $import->version || ! hash_equals($data['snapshot_sha256'], $import->sha256)) {
                    $this->invalid();
                }

                return ['batch_id' => $pending->batch_id, 'package' => $package, 'count' => count($data['admissions'])];
            }
            $rows = DB::table('admissions as a')
                ->join('tickets as t', 't.id', '=', 'a.ticket_id')
                ->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->join('access_points as g', 'g.id', '=', 'a.access_point_id')
                ->leftJoin('node_import_ids as ti', fn ($join) => $join->on('ti.local_id', '=', 'a.ticket_id')->where('ti.kind', '=', 'ticket'))
                ->leftJoin('node_import_ids as gi', fn ($join) => $join->on('gi.local_id', '=', 'a.access_point_id')->where('gi.kind', '=', 'gate'))
                ->leftJoin('node_import_ids as si', fn ($join) => $join->on('si.local_id', '=', 'a.scanned_by_user_id')->where('si.kind', '=', 'user'))
                ->where('r.event_id', $import->event_id)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_synced_admissions as s')->whereColumn('s.admission_id', 'a.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_walk_in_issuances as w')
                    ->whereColumn('w.ticket_id', 'a.ticket_id')->whereNull('w.synced_at'))
                ->orderBy('a.id')->limit(100)
                ->get(['a.id', 'a.client_scan_id', 'a.credential_version', 'a.direction', 'a.admitted_at', 'g.event_id as gate_event_id',
                    'ti.cloud_id as cloud_ticket_id', 'gi.cloud_id as cloud_gate_id', 'si.cloud_id as cloud_staff_id']);
            if ($rows->isEmpty()) {
                return null;
            }
            $items = [];
            foreach ($rows as $row) {
                if (! $row->cloud_ticket_id || ! $row->cloud_gate_id || ! $row->cloud_staff_id || (int) $row->gate_event_id !== (int) $import->event_id) {
                    $this->invalid();
                }
                $items[] = ['admission_id' => (int) $row->id, 'ticket_id' => (int) $row->cloud_ticket_id,
                    'gate_id' => (int) $row->cloud_gate_id, 'staff_id' => (int) $row->cloud_staff_id,
                    'scan_id' => $row->client_scan_id, 'credential_version' => (int) $row->credential_version,
                    'direction' => $row->direction, 'admitted_at' => CarbonImmutable::parse($row->admitted_at, 'UTC')->utc()->toISOString()];
            }
            $data = ['schema_version' => 2, 'batch_id' => (string) Str::uuid(), 'node_id' => $nodeId,
                'event_id' => $cloudEventId, 'snapshot_id' => $import->snapshot_id, 'version' => (int) $import->version,
                'snapshot_sha256' => $import->sha256, 'admissions' => $items];
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
            $this->validator->execute($payload);
            $package = $this->envelope->seal($payload, $secret);
            DB::table('node_admission_batches')->insert([
                'event_id' => $import->event_id, 'batch_id' => $data['batch_id'], 'package_ciphertext' => Crypt::encryptString($package),
                'payload_hash' => hash('sha256', $payload), 'created_at' => now(),
            ]);

            return ['batch_id' => $data['batch_id'], 'package' => $package, 'count' => count($items)];
        });
    }
}
