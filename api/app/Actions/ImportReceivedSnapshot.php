<?php

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportReceivedSnapshot
{
    public function __construct(private BindRuntimeDatabase $runtime, private ValidateSnapshotPayload $validator) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['import' => 'Snapshot cannot be imported. Check the staged version and node data.']);
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    private function link(string $kind, int $cloudId, int $localId, bool $roleKnown = false): void
    {
        DB::table('node_import_ids')->insert(['kind' => $kind, 'cloud_id' => $cloudId, 'local_id' => $localId, 'role_known' => $roleKnown]);
    }

    private function user(array $identity, string $passwordHash): int
    {
        $link = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $identity['id'])->first();
        $roleKnown = array_key_exists('role', $identity);
        if ($link) {
            $user = DB::table('users')->where('id', $link->local_id)->first();
            if (! $user || $user->name !== $identity['name'] || $user->email !== $identity['email']
                || (array_key_exists('student_id', $identity) && $user->student_id !== null && $user->student_id !== $identity['student_id'])
                || ($roleKnown && $link->role_known && $user->role !== $identity['role'])) {
                $this->invalid();
            }
            $updates = [];
            if ($roleKnown && ! $link->role_known) {
                $updates['role'] = $identity['role'];
                DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $identity['id'])->update(['role_known' => true]);
            }
            if (array_key_exists('student_id', $identity) && $user->student_id === null && $identity['student_id'] !== null) {
                $updates['student_id'] = $identity['student_id'];
            }
            if ($updates) {
                $updates['updated_at'] = now();
                DB::table('users')->where('id', $user->id)->update($updates);
            }

            return (int) $user->id;
        }
        $id = DB::table('users')->insertGetId([
            'name' => $identity['name'], 'email' => $identity['email'], 'student_id' => $identity['student_id'] ?? null,
            'role' => $identity['role'] ?? 'student', 'password' => $passwordHash, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->link('user', $identity['id'], $id, $roleKnown);

        return $id;
    }

    private function venue(array $venue): int
    {
        $link = DB::table('node_import_ids')->where('kind', 'venue')->where('cloud_id', $venue['id'])->first();
        if ($link) {
            $existing = DB::table('venues')->where('id', $link->local_id)->first();
            if (! $existing || $existing->name !== $venue['name'] || $existing->code !== $venue['code'] || $existing->location !== $venue['location']) {
                $this->invalid();
            }

            return (int) $existing->id;
        }
        $id = DB::table('venues')->insertGetId([
            'name' => $venue['name'], 'code' => $venue['code'], 'location' => $venue['location'],
            'default_capacity' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->link('venue', $venue['id'], $id);

        return $id;
    }

    public function execute(int $cloudEventId): int
    {
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1) {
            $this->invalid();
        }
        $this->runtime->execute();
        try {
            return DB::transaction(function () use ($cloudEventId) {
                DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
                $head = DB::table('received_event_heads')->where('event_id', $cloudEventId)->lockForUpdate()->first();
                if (! $head || $head->version < 1) {
                    $this->invalid();
                }
                $record = DB::table('received_snapshots')->where('event_id', $cloudEventId)->where('version', $head->version)->first();
                if (! $record || (int) $record->schema_version !== 1) {
                    $this->invalid();
                }
                $json = Crypt::decryptString($record->payload_ciphertext);
                if (! hash_equals($record->sha256, hash('sha256', $json))) {
                    $this->invalid();
                }
                $payload = $this->validator->execute($json);
                if ($payload['snapshot_id'] !== $record->id || $payload['version'] !== (int) $record->version || $payload['event']['id'] !== $cloudEventId) {
                    $this->invalid();
                }
                $prior = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
                if ($prior) {
                    if ($prior->snapshot_id !== $record->id || (int) $prior->version !== (int) $record->version || ! hash_equals($prior->sha256, $record->sha256)) {
                        $this->invalid();
                    }

                    return (int) $prior->event_id;
                }
                $event = $payload['event'];
                $password = Hash::make(Str::random(64));
                $organizer = $this->user($event['organizer'], $password);
                $venue = $this->venue($event['venue']);
                $eventId = DB::table('events')->insertGetId([
                    'organizer_id' => $organizer, 'venue_id' => $venue, 'title' => $event['title'], 'slug' => $event['slug'],
                    'description' => $event['description'], 'capacity' => $event['capacity'], 'status' => $event['status'],
                    'starts_at' => $this->time($event['starts_at']), 'ends_at' => $this->time($event['ends_at']),
                    'registration_opens_at' => $this->time($event['registration_opens_at']),
                    'registration_closes_at' => $this->time($event['registration_closes_at']),
                    'registration_closed_at' => $this->time($event['registration_closed_at']),
                    'snapshot_version' => $payload['version'], 'snapshot_prepared_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->link('event', $cloudEventId, $eventId);
                $gates = [];
                foreach ($payload['access_points'] as $gate) {
                    $id = DB::table('access_points')->insertGetId([
                        'event_id' => $eventId, 'name' => $gate['name'], 'code' => $gate['code'], 'is_active' => $gate['is_active'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $gates[$gate['id']] = $id;
                    $this->link('gate', $gate['id'], $id);
                }
                foreach ($payload['staff'] as $assignment) {
                    $staffId = $this->user($assignment['user'], $password);
                    $id = DB::table('event_staff')->insertGetId([
                        'event_id' => $eventId, 'user_id' => $staffId,
                        'access_point_id' => $assignment['access_point_id'] === null ? null : $gates[$assignment['access_point_id']],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->link('staff', $assignment['id'], $id);
                }
                foreach ($payload['registrations'] as $registration) {
                    $userId = $this->user($registration['attendee'], $password);
                    $registrationId = DB::table('registrations')->insertGetId([
                        'event_id' => $eventId, 'user_id' => $userId, 'status' => $registration['status'],
                        'cancelled_at' => $this->time($registration['cancelled_at']),
                        'created_at' => $this->time($registration['created_at']), 'updated_at' => now(),
                    ]);
                    $this->link('registration', $registration['id'], $registrationId);
                    if ($registration['ticket'] !== null) {
                        $ticket = $registration['ticket'];
                        $ticketId = DB::table('tickets')->insertGetId([
                            'registration_id' => $registrationId, 'public_id' => $ticket['public_id'], 'qr_hash' => $ticket['qr_hash'],
                            'status' => $ticket['status'], 'credential_version' => $ticket['credential_version'],
                            'issued_at' => $this->time($ticket['issued_at']), 'revoked_at' => $this->time($ticket['revoked_at']),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->link('ticket', $ticket['id'], $ticketId);
                    }
                }
                DB::table('node_event_imports')->insert([
                    'cloud_event_id' => $cloudEventId, 'event_id' => $eventId, 'snapshot_id' => $record->id,
                    'version' => $record->version, 'sha256' => $record->sha256, 'imported_at' => now(),
                ]);

                return $eventId;
            });
        } catch (DecryptException|QueryException) {
            $this->invalid();
        }
    }
}
