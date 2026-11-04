<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueNodeWalkIn
{
    public function execute(User $staff, int $eventId, string $requestId, string $name, string $email, string $kind, ?string $studentId): Ticket
    {
        $name = trim($name);
        $email = Str::lower(trim($email));
        $studentId = $studentId === null ? null : trim($studentId);
        if (config('ingress.mode') !== 'node' || ! Str::isUuid($requestId)
            || $name === '' || mb_strlen($name) > 255 || str_contains($name, "\0")
            || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! in_array($kind, ['student', 'guest', 'alumni'], true)
            || ($kind === 'student' && ($studentId === null || $studentId === '' || mb_strlen($studentId) > 255 || str_contains($studentId, "\0")))
            || ($kind !== 'student' && $studentId !== null)) {
            throw ValidationException::withMessages(['walk_in' => 'Provide valid walk-in details and a request ID.']);
        }
        $requestHash = hash('sha256', json_encode([$eventId, $staff->id, $name, $email, $kind, $studentId], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($staff, $eventId, $requestId, $name, $email, $kind, $studentId, $requestHash) {
                if (! app(NodeAdmissionAccess::class)->allows($staff, $eventId, true)) {
                    abort(403, 'Node authority or staff session is unavailable.');
                }
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                if (! EventStaff::where('event_id', $eventId)->where('user_id', $staff->id)->lockForUpdate()->exists()) {
                    abort(403, 'You are not assigned to this event.');
                }
                $existing = DB::table('node_walk_in_issuances')->where('client_request_id', $requestId)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw ValidationException::withMessages(['walk_in' => 'The request ID was used for another walk-in.']);
                    }
                    $ticket = Ticket::whereKey($existing->ticket_id)->lockForUpdate()->first();
                    $registration = $ticket?->registration;
                    if (! $event->isPublished() || ! $ticket || ! $ticket->isActive() || ! $registration
                        || (int) $registration->id !== (int) $existing->registration_id
                        || (int) $registration->event_id !== $eventId || $registration->attendee_type !== 'walk_in'
                        || $registration->status !== Registration::STATUS_CONFIRMED || $ticket->getQrPayload() === null) {
                        throw ValidationException::withMessages(['walk_in' => 'The previously issued walk-in pass is no longer active.']);
                    }

                    return $ticket;
                }
                if (! $event->isPublished() || now()->gt($event->ends_at)) {
                    throw ValidationException::withMessages(['walk_in' => 'This event is not accepting walk-ins.']);
                }
                $exists = Registration::where('event_id', $eventId)->where(function ($query) use ($email, $studentId) {
                    $query->where('guest_email', $email)
                        ->orWhereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [$email]));
                    if ($studentId !== null) {
                        $query->orWhere('guest_student_id', $studentId)
                            ->orWhereHas('user', fn ($user) => $user->where('student_id', $studentId));
                    }
                })->exists();
                if ($exists) {
                    throw ValidationException::withMessages(['walk_in' => 'This attendee already has a registration for the event.']);
                }
                if (Registration::where('event_id', $eventId)->where('status', Registration::STATUS_CONFIRMED)->count() >= $event->capacity) {
                    throw ValidationException::withMessages(['walk_in' => 'Event capacity has been reached.']);
                }
                $registration = Registration::create([
                    'event_id' => $eventId, 'user_id' => null, 'attendee_type' => 'walk_in',
                    'guest_name' => $name, 'guest_email' => $email, 'guest_student_id' => $studentId,
                    'walk_in_kind' => $kind, 'status' => Registration::STATUS_CONFIRMED,
                ]);
                $secret = bin2hex(random_bytes(32));
                $ticket = Ticket::create([
                    'registration_id' => $registration->id, 'public_id' => 'ING-'.strtoupper(Str::random(10)),
                    'qr_hash' => hash('sha256', $secret), 'encrypted_secret' => Crypt::encryptString($secret),
                    'status' => Ticket::STATUS_ACTIVE, 'credential_version' => 1, 'issued_at' => now(),
                ]);
                DB::table('node_walk_in_issuances')->insert([
                    'issuance_id' => (string) Str::uuid(), 'client_request_id' => $requestId,
                    'event_id' => $eventId, 'registration_id' => $registration->id, 'ticket_id' => $ticket->id,
                    'issued_by_user_id' => $staff->id, 'request_hash' => $requestHash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['walk_in' => 'The walk-in conflicts with an existing request or registration.']);
        }
    }
}
