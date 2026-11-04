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

    private function removePriorImport(object $prior, int $cloudEventId): int
    {
        $event = DB::table('events')->where('id', $prior->event_id)->lockForUpdate()->first();
        $eventLink = DB::table('node_import_ids')->where('kind', 'event')->where('cloud_id', $cloudEventId)->first();
        if (! $event || ! $eventLink || (int) $eventLink->local_id !== (int) $event->id
            || $event->status !== 'published' || $event->snapshot_prepared_at === null
            || (int) $event->snapshot_version !== (int) $prior->version) {
            $this->invalid();
        }
        $old = DB::table('received_snapshots')->where('id', $prior->snapshot_id)
            ->where('event_id', $cloudEventId)->where('version', $prior->version)->first();
        if (! $old || ! in_array((int) $old->schema_version, [1, 2, 3], true) || ! hash_equals($prior->sha256, $old->sha256)) {
            $this->invalid();
        }
        $json = Crypt::decryptString($old->payload_ciphertext);
        if (! hash_equals($prior->sha256, hash('sha256', $json))) {
            $this->invalid();
        }
        $payload = $this->validator->execute($json);
        if ($payload['schema_version'] !== (int) $old->schema_version || $payload['snapshot_id'] !== $prior->snapshot_id || $payload['version'] !== (int) $prior->version
            || $payload['event']['id'] !== $cloudEventId) {
            $this->invalid();
        }
        $eventId = (int) $event->id;
        $hasTicketAdmission = DB::table('admissions')->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
            ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
            ->where('registrations.event_id', $eventId)->exists();
        $hasGateAdmission = DB::table('admissions')->join('access_points', 'access_points.id', '=', 'admissions.access_point_id')
            ->where('access_points.event_id', $eventId)->exists();
        if ($hasTicketAdmission || $hasGateAdmission) {
            $this->invalid();
        }
        $expected = [
            'gate' => array_column($payload['access_points'], 'id'),
            'staff' => array_column($payload['staff'], 'id'),
            'registration' => array_column($payload['registrations'], 'id'),
            'ticket' => array_values(array_map(fn ($registration) => $registration['ticket']['id'],
                array_filter($payload['registrations'], fn ($registration) => $registration['ticket'] !== null))),
        ];
        $actual = [
            'gate' => DB::table('access_points')->where('event_id', $eventId)->pluck('id')->all(),
            'staff' => DB::table('event_staff')->where('event_id', $eventId)->pluck('id')->all(),
            'registration' => DB::table('registrations')->where('event_id', $eventId)->pluck('id')->all(),
            'ticket' => DB::table('tickets')->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
                ->where('registrations.event_id', $eventId)->pluck('tickets.id')->all(),
        ];
        foreach ($expected as $kind => $cloudIds) {
            $localIds = DB::table('node_import_ids')->where('kind', $kind)->whereIntegerInRaw('cloud_id', $cloudIds)->pluck('local_id')->all();
            $localIds = array_map('intval', $localIds);
            $actualIds = array_map('intval', $actual[$kind]);
            sort($localIds);
            sort($actualIds);
            if (count($localIds) !== count($cloudIds) || $localIds !== $actualIds) {
                $this->invalid();
            }
        }
        if (DB::table('event_staff')->whereIntegerInRaw('access_point_id', $actual['gate'])
            ->where('event_id', '!=', $eventId)->exists()) {
            $this->invalid();
        }
        foreach ($expected as $kind => $cloudIds) {
            DB::table('node_import_ids')->where('kind', $kind)->whereIntegerInRaw('cloud_id', $cloudIds)->delete();
        }
        DB::table('tickets')->whereIntegerInRaw('id', $actual['ticket'])->delete();
        DB::table('registrations')->where('event_id', $eventId)->delete();
        DB::table('event_staff')->where('event_id', $eventId)->delete();
        DB::table('access_points')->where('event_id', $eventId)->delete();

        return $eventId;
    }

    public function execute(int $cloudEventId): int
    {
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1) {
            $this->invalid();
        }
        $this->runtime->execute();
        try {
            return DB::transaction(function () use ($cloudEventId) {
                // ponytail: serialize imports while users and venues are shared; partition locks if import throughput becomes a bottleneck.
                DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
                $head = DB::table('received_event_heads')->where('event_id', $cloudEventId)->lockForUpdate()->first();
                if (! $head || $head->version < 1) {
                    $this->invalid();
                }
                $record = DB::table('received_snapshots')->where('event_id', $cloudEventId)->where('version', $head->version)->first();
                if (! $record || ! in_array((int) $record->schema_version, [1, 2, 3], true)) {
                    $this->invalid();
                }
                $json = Crypt::decryptString($record->payload_ciphertext);
                if (! hash_equals($record->sha256, hash('sha256', $json))) {
                    $this->invalid();
                }
                $payload = $this->validator->execute($json);
                if ($payload['schema_version'] !== (int) $record->schema_version || $payload['snapshot_id'] !== $record->id || $payload['version'] !== (int) $record->version || $payload['event']['id'] !== $cloudEventId) {
                    $this->invalid();
                }
                $prior = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
                if ($prior && $prior->snapshot_id === $record->id && (int) $prior->version === (int) $record->version
                    && hash_equals($prior->sha256, $record->sha256)) {
                    return (int) $prior->event_id;
                }
                if (DB::table('node_event_authorities')->where('cloud_event_id', $cloudEventId)->exists()) {
                    $this->invalid();
                }
                if ($prior && (int) $record->version <= (int) $prior->version) {
                    $this->invalid();
                }
                $eventId = $prior ? $this->removePriorImport($prior, $cloudEventId) : null;
                $event = $payload['event'];
                $password = Hash::make(Str::random(64));
                $organizer = $this->user($event['organizer'], $password);
                $venue = $this->venue($event['venue']);
                $eventData = [
                    'organizer_id' => $organizer, 'venue_id' => $venue, 'title' => $event['title'], 'slug' => $event['slug'],
                    'description' => $event['description'], 'capacity' => $event['capacity'], 'status' => $event['status'],
                    'starts_at' => $this->time($event['starts_at']), 'ends_at' => $this->time($event['ends_at']),
                    'registration_opens_at' => $this->time($event['registration_opens_at']),
                    'registration_closes_at' => $this->time($event['registration_closes_at']),
                    'registration_closed_at' => $this->time($event['registration_closed_at']),
                    're_entry_allowed' => $event['re_entry_allowed'] ?? false,
                    'snapshot_version' => $payload['version'], 'snapshot_prepared_at' => now(), 'updated_at' => now(),
                ];
                if ($eventId === null) {
                    $eventId = DB::table('events')->insertGetId($eventData + ['created_at' => now()]);
                    $this->link('event', $cloudEventId, $eventId);
                } else {
                    DB::table('events')->where('id', $eventId)->update($eventData);
                }
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
                    $kind = $registration['attendee']['kind'] ?? 'student';
                    $userId = $kind === 'student' ? $this->user(array_diff_key($registration['attendee'], ['kind' => true]), $password) : null;
                    $registrationId = DB::table('registrations')->insertGetId([
                        'event_id' => $eventId, 'user_id' => $userId, 'attendee_type' => $kind,
                        'guest_name' => $userId === null ? $registration['attendee']['name'] : null,
                        'guest_email' => $userId === null ? $registration['attendee']['email'] : null,
                        'status' => $registration['status'],
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
                DB::table('node_event_imports')->updateOrInsert(['cloud_event_id' => $cloudEventId], [
                    'event_id' => $eventId, 'snapshot_id' => $record->id,
                    'version' => $record->version, 'sha256' => $record->sha256, 'imported_at' => now(),
                ]);

                return $eventId;
            });
        } catch (DecryptException|QueryException) {
            $this->invalid();
        }
    }
}
