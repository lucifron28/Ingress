<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveAdmissionBatch
{
    public function __construct(private AdmissionSyncEnvelope $envelope, private ValidateAdmissionBatch $validator,
        private ValidateSnapshotPayload $snapshots) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Admission synchronization was rejected.']);
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
                $existingBatch = DB::table('cloud_admission_batches')->where('batch_id', $data['batch_id'])->first();
                if ($existingBatch) {
                    if ((int) $existingBatch->event_id !== $data['event_id'] || $existingBatch->node_id !== $nodeId
                        || ! hash_equals($existingBatch->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return ['batch_id' => $data['batch_id'], 'receipt' => $existingBatch->receipt];
                }
                $snapshotJson = Crypt::decryptString($snapshot->payload_ciphertext);
                if (! hash_equals($snapshot->sha256, hash('sha256', $snapshotJson))) {
                    $this->invalid();
                }
                $roster = $this->snapshots->execute($snapshotJson);
                if ($roster['snapshot_id'] !== $data['snapshot_id'] || $roster['version'] !== $data['version']
                    || $roster['event']['id'] !== $data['event_id']) {
                    $this->invalid();
                }
                $tickets = $gates = $staff = [];
                foreach ($roster['registrations'] as $registration) {
                    if ($registration['status'] === 'confirmed' && $registration['ticket'] !== null && $registration['ticket']['status'] === 'active') {
                        $tickets[$registration['ticket']['id']] = $registration['ticket'];
                    }
                }
                foreach ($roster['access_points'] as $gate) {
                    if ($gate['is_active']) {
                        $gates[$gate['id']] = true;
                    }
                }
                foreach ($roster['staff'] as $assignment) {
                    $staff[$assignment['user']['id']] = $assignment;
                }
                foreach ($data['admissions'] as $item) {
                    $ticket = $tickets[$item['ticket_id']] ?? null;
                    $assignment = $staff[$item['staff_id']] ?? null;
                    if (! $ticket || $ticket['credential_version'] !== $item['credential_version'] || ! isset($gates[$item['gate_id']])
                        || ! $assignment || ($assignment['access_point_id'] !== null && $assignment['access_point_id'] !== $item['gate_id'])) {
                        $this->invalid();
                    }
                    $cloudTicket = DB::table('tickets')->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
                        ->where('tickets.id', $item['ticket_id'])->where('registrations.event_id', $data['event_id'])
                        ->select('tickets.id', 'tickets.qr_hash', 'tickets.status', 'tickets.credential_version', 'registrations.status as registration_status')->first();
                    if (! $cloudTicket || $cloudTicket->status !== 'active' || $cloudTicket->registration_status !== 'confirmed'
                        || (int) $cloudTicket->credential_version !== $ticket['credential_version']
                        || ! hash_equals($cloudTicket->qr_hash, $ticket['qr_hash']) || ! DB::table('access_points')->where('id', $item['gate_id'])->where('event_id', $data['event_id'])->exists()
                        || ! DB::table('users')->where('id', $item['staff_id'])->exists()) {
                        $this->invalid();
                    }
                    $already = DB::table('cloud_synced_admissions')->where('node_id', $nodeId)->where('node_admission_id', $item['admission_id'])->first();
                    if ($already) {
                        $admission = DB::table('admissions')->where('id', $already->admission_id)->first();
                        if (! $admission || (int) $already->event_id !== $data['event_id']
                            || (int) $admission->ticket_id !== $item['ticket_id'] || (int) $admission->access_point_id !== $item['gate_id']
                            || (int) $admission->scanned_by_user_id !== $item['staff_id'] || $admission->client_scan_id !== $item['scan_id']
                            || (int) $admission->credential_version !== $item['credential_version']
                            || ! CarbonImmutable::parse($admission->admitted_at, 'UTC')->equalTo(CarbonImmutable::parse($item['admitted_at']))) {
                            $this->invalid();
                        }

                        continue;
                    }
                    if (DB::table('admissions')->where('ticket_id', $item['ticket_id'])->exists()) {
                        $this->invalid();
                    }
                    $admissionId = DB::table('admissions')->insertGetId([
                        'ticket_id' => $item['ticket_id'], 'access_point_id' => $item['gate_id'],
                        'scanned_by_user_id' => $item['staff_id'], 'client_scan_id' => $item['scan_id'],
                        'credential_version' => $item['credential_version'], 'admitted_at' => CarbonImmutable::parse($item['admitted_at'])->utc(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('cloud_synced_admissions')->insert([
                        'node_id' => $nodeId, 'node_admission_id' => $item['admission_id'], 'admission_id' => $admissionId, 'event_id' => $data['event_id'],
                    ]);
                }
                $receipt = $this->envelope->receipt($data['batch_id'], $hash, $secret);
                DB::table('cloud_admission_batches')->insert([
                    'batch_id' => $data['batch_id'], 'event_id' => $data['event_id'], 'node_id' => $nodeId,
                    'payload_hash' => $hash, 'receipt' => $receipt, 'received_at' => now(),
                ]);

                return ['batch_id' => $data['batch_id'], 'receipt' => $receipt];
            });
        } catch (UniqueConstraintViolationException) {
            $this->invalid();
        }
    }
}
