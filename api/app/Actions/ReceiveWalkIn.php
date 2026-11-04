<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveWalkIn
{
    public function __construct(private AdmissionSyncEnvelope $envelope, private ValidateWalkInSync $validator,
        private ValidateSnapshotPayload $snapshots) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Walk-in synchronization was rejected.']);
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
                $existing = DB::table('cloud_walk_in_receipts')->where('node_id', $nodeId)->where('issuance_id', $data['issuance_id'])->first();
                if ($existing) {
                    if ((int) $existing->event_id !== $data['event_id'] || ! hash_equals($existing->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return $this->receipt($data['issuance_id'], $hash, (int) $existing->registration_id, (int) $existing->ticket_id, $secret);
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
                if (! $assigned) {
                    $this->invalid();
                }
                $duplicate = DB::table('registrations as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                    ->where('r.event_id', $data['event_id'])->where(function ($query) use ($data) {
                        $query->where('r.guest_email', $data['email'])->orWhereRaw('LOWER(u.email) = ?', [$data['email']]);
                        if ($data['student_id'] !== null) {
                            $query->orWhere('r.guest_student_id', $data['student_id'])->orWhere('u.student_id', $data['student_id']);
                        }
                    })->exists();
                if ($duplicate || DB::table('registrations')->where('event_id', $data['event_id'])->where('status', 'confirmed')->count() >= $event->capacity) {
                    $this->invalid();
                }
                $issuedAt = CarbonImmutable::parse($data['issued_at'])->utc();
                if ($issuedAt->greaterThan(now()->addMinutes(5)) || $issuedAt->greaterThan(CarbonImmutable::parse($event->ends_at))) {
                    $this->invalid();
                }
                $registrationId = DB::table('registrations')->insertGetId([
                    'event_id' => $data['event_id'], 'user_id' => null, 'attendee_type' => 'walk_in',
                    'guest_name' => $data['name'], 'guest_email' => $data['email'], 'walk_in_kind' => $data['kind'],
                    'guest_student_id' => $data['student_id'], 'status' => 'confirmed', 'created_at' => $issuedAt, 'updated_at' => now(),
                ]);
                $ticketId = DB::table('tickets')->insertGetId([
                    'registration_id' => $registrationId, 'public_id' => $data['public_id'], 'qr_hash' => $data['qr_hash'],
                    'encrypted_secret' => null, 'status' => 'active', 'credential_version' => 1, 'issued_at' => $issuedAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('cloud_walk_in_receipts')->insert([
                    'node_id' => $nodeId, 'issuance_id' => $data['issuance_id'], 'event_id' => $data['event_id'],
                    'registration_id' => $registrationId, 'ticket_id' => $ticketId, 'payload_hash' => $hash, 'received_at' => now(),
                ]);

                return $this->receipt($data['issuance_id'], $hash, $registrationId, $ticketId, $secret);
            });
        } catch (UniqueConstraintViolationException) {
            $this->invalid();
        }
    }

    private function receipt(string $issuanceId, string $hash, int $registrationId, int $ticketId, string $secret): array
    {
        return ['issuance_id' => $issuanceId, 'registration_id' => $registrationId, 'ticket_id' => $ticketId,
            'receipt' => $this->envelope->walkInReceipt($issuanceId, $hash, $registrationId, $ticketId, $secret)];
    }
}
