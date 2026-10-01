<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterForEvent
{
    /**
     * Concurrency-safe event registration with row-level lock and ticket issuance.
     *
     * @return array{registration: Registration, ticket: Ticket, qr_payload: string}
     */
    public function execute(User $user, int $eventId): array
    {
        return DB::transaction(function () use ($user, $eventId) {
            // Pessimistic write lock on Event row to strictly enforce: confirmed <= capacity
            $event = Event::where('id', $eventId)->lockForUpdate()->first();

            if (! $event) {
                throw ValidationException::withMessages([
                    'event' => ['Event does not exist.'],
                ]);
            }

            if (! $event->isPublished()) {
                throw ValidationException::withMessages([
                    'event' => ['Event is not currently published.'],
                ]);
            }

            $now = Carbon::now();
            if ($now->lt($event->registration_opens_at)) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration for this event has not opened yet.'],
                ]);
            }

            if ($now->gt($event->registration_closes_at)) {
                throw ValidationException::withMessages([
                    'registration' => ['Registration for this event has already closed.'],
                ]);
            }

            if ($now->gt($event->ends_at)) {
                throw ValidationException::withMessages([
                    'registration' => ['Event has already concluded.'],
                ]);
            }

            // Check if user is already confirmed for this event
            $existing = Registration::where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'registration' => ['You are already registered for this event.'],
                ]);
            }

            // Concurrency-safe capacity check against row-locked event
            $confirmedCount = Registration::where('event_id', $event->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->count();

            if ($confirmedCount >= $event->capacity) {
                throw ValidationException::withMessages([
                    'capacity' => ['Event capacity has been reached. No seats available.'],
                ]);
            }

            // Create confirmed registration
            $registration = Registration::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'status' => Registration::STATUS_CONFIRMED,
            ]);

            // Generate cryptographically secure opaque QR credential (256-bit CSPRNG secret)
            $publicId = 'ING-' . strtoupper(Str::random(10));
            $secret = bin2hex(random_bytes(32)); // 64 hex characters
            $qrHash = hash('sha256', $secret);

            $ticket = Ticket::create([
                'registration_id' => $registration->id,
                'public_id' => $publicId,
                'qr_hash' => $qrHash,
                'encrypted_secret' => Crypt::encryptString($secret),
                'status' => Ticket::STATUS_ACTIVE,
                'issued_at' => Carbon::now(),
            ]);

            $rawPayload = "ingress:v1:{$publicId}:{$secret}";

            return [
                'registration' => $registration,
                'ticket' => $ticket,
                'qr_payload' => $rawPayload,
            ];
        });
    }
}
