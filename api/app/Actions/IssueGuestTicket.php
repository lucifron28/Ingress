<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueGuestTicket
{
    public function execute(int $eventId, string $name, string $email, string $type): Registration
    {
        $name = trim($name);
        $email = Str::lower(trim($email));
        if (config('ingress.mode') !== 'cloud' || ! in_array($type, ['guest', 'alumni'], true)
            || $name === '' || mb_strlen($name) > 255 || str_contains($name, "\0")
            || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['guest' => 'Provide a valid guest name, email, and attendee type.']);
        }

        return DB::transaction(function () use ($eventId, $name, $email, $type) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if (! $event->isRegistrationOpen()) {
                throw ValidationException::withMessages(['guest' => 'Registration is not open for this event.']);
            }
            $exists = Registration::where('event_id', $eventId)->where(function ($query) use ($email) {
                $query->where('guest_email', $email)
                    ->orWhereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [$email]));
            })->exists();
            if ($exists) {
                throw ValidationException::withMessages(['guest' => 'That email already has a registration for this event.']);
            }
            if (Registration::where('event_id', $eventId)->where('status', Registration::STATUS_CONFIRMED)->count() >= $event->capacity) {
                throw ValidationException::withMessages(['guest' => 'Event capacity has been reached.']);
            }
            $secret = bin2hex(random_bytes(32));
            $registration = Registration::create([
                'event_id' => $eventId, 'user_id' => null, 'attendee_type' => $type,
                'guest_name' => $name, 'guest_email' => $email, 'status' => Registration::STATUS_CONFIRMED,
            ]);
            Ticket::create([
                'registration_id' => $registration->id, 'public_id' => 'ING-'.strtoupper(Str::random(10)),
                'qr_hash' => hash('sha256', $secret), 'encrypted_secret' => Crypt::encryptString($secret),
                'status' => Ticket::STATUS_ACTIVE, 'credential_version' => 1, 'issued_at' => now(),
            ]);

            return $registration;
        });
    }
}
