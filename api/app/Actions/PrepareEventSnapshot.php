<?php

namespace App\Actions;

use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrepareEventSnapshot
{
    public function __construct(private ReadPreparedSnapshot $reader, private ValidateSnapshotPayload $validator) {}

    /** @return array{id: string, version: int, sha256: string} */
    public function execute(int $eventId): array
    {
        if (config('ingress.mode') !== 'cloud') {
            throw ValidationException::withMessages(['event' => 'Snapshots can only be prepared on the cloud.']);
        }

        return DB::transaction(function () use ($eventId) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();

            if (! $event->isPublished()) {
                throw ValidationException::withMessages(['event' => 'Only published events can be prepared.']);
            }

            if (Admission::whereHas('ticket.registration', fn ($query) => $query->where('event_id', $event->id))->exists()) {
                throw ValidationException::withMessages(['event' => 'Events with existing admissions cannot be prepared until admission history can be transferred.']);
            }

            if ($event->snapshot_prepared_at !== null) {
                $existing = $this->reader->execute($event)['record'];

                return ['id' => $existing->id, 'version' => (int) $existing->version, 'sha256' => $existing->sha256];
            }

            if ($event->ends_at->isPast()) {
                throw ValidationException::withMessages(['event' => 'Ended events cannot be prepared.']);
            }

            if ($event->registration_closed_at === null
                && now()->lte($event->registration_closes_at) && now()->lte($event->ends_at)) {
                throw ValidationException::withMessages(['registration' => 'Close registration before preparing a snapshot.']);
            }

            $event->load(['venue', 'organizer', 'accessPoints', 'staffAssignments.user']);
            $registrations = Registration::with(['user', 'ticket'])->where('event_id', $event->id)->orderBy('id')->get();
            $schemaVersion = $registrations->contains(fn ($registration) => $registration->user_id === null) ? 2 : 1;
            $id = (string) Str::uuid();
            $version = $event->snapshot_version + 1;
            $identity = fn ($user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role,
            ];
            $payload = [
                'schema_version' => $schemaVersion, 'snapshot_id' => $id, 'version' => $version,
                'event' => [
                    'id' => $event->id, 'title' => $event->title, 'slug' => $event->slug,
                    'description' => $event->description, 'capacity' => $event->capacity,
                    'status' => $event->status, 'starts_at' => $event->starts_at->toISOString(),
                    'ends_at' => $event->ends_at->toISOString(),
                    'registration_opens_at' => $event->registration_opens_at->toISOString(),
                    'registration_closes_at' => $event->registration_closes_at->toISOString(),
                    'registration_closed_at' => $event->registration_closed_at?->toISOString(),
                    'venue' => ['id' => $event->venue->id, 'name' => $event->venue->name,
                        'code' => $event->venue->code, 'location' => $event->venue->location],
                    'organizer' => $identity($event->organizer),
                ],
                'access_points' => $event->accessPoints->sortBy('id')->values()->map(fn ($gate) => [
                    'id' => $gate->id, 'name' => $gate->name, 'code' => $gate->code, 'is_active' => $gate->is_active,
                ])->all(),
                'staff' => $event->staffAssignments->sortBy('id')->values()->map(fn ($assignment) => [
                    'id' => $assignment->id, 'access_point_id' => $assignment->access_point_id,
                    'user' => $identity($assignment->user),
                ])->all(),
                'registrations' => $registrations->map(fn ($registration) => [
                    'id' => $registration->id, 'status' => $registration->status,
                    'created_at' => $registration->created_at->toISOString(),
                    'cancelled_at' => $registration->cancelled_at?->toISOString(),
                    'attendee' => ['id' => $registration->user?->id, 'name' => $registration->attendeeName(),
                        'email' => $registration->attendeeEmail(), 'student_id' => $registration->attendeeStudentId()]
                        + ($schemaVersion === 2 ? ['kind' => $registration->attendee_type] : []),
                    'ticket' => $registration->ticket ? [
                        'id' => $registration->ticket->id, 'public_id' => $registration->ticket->public_id,
                        'qr_hash' => $registration->ticket->qr_hash, 'status' => $registration->ticket->status,
                        'credential_version' => $registration->ticket->credential_version,
                        'issued_at' => $registration->ticket->issued_at->toISOString(),
                        'revoked_at' => $registration->ticket->revoked_at?->toISOString(),
                    ] : null,
                ])->all(),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->validator->execute($json);
            $checksum = hash('sha256', $json);
            $preparedAt = now();
            DB::table('event_snapshots')->insert([
                'id' => $id, 'event_id' => $event->id, 'version' => $version, 'schema_version' => $schemaVersion,
                'sha256' => $checksum, 'payload_ciphertext' => Crypt::encryptString($json),
                'created_at' => $preparedAt, 'updated_at' => $preparedAt,
            ]);
            $event->snapshot_version = $version;
            $event->snapshot_prepared_at = $preparedAt;
            $event->save();

            return ['id' => $id, 'version' => $version, 'sha256' => $checksum];
        });
    }
}
