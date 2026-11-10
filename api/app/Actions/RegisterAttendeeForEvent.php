<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterAttendeeForEvent
{
    /**
     * Register one attendee under the event lock shared by student and guest paths.
     *
     * @return array{registration: Registration, ticket: Ticket, qr_payload: string}
     */
    public function execute(
        int $eventId,
        ?int $categoryId,
        ?User $user = null,
        ?string $guestName = null,
        ?string $guestEmail = null,
        string $guestType = 'guest',
    ): array {
        return DB::transaction(function () use ($eventId, $categoryId, $user, $guestName, $guestEmail, $guestType): array {
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();

            if (! $event) {
                throw ValidationException::withMessages(['event' => ['Event does not exist.']]);
            }

            if (! $event->isRegistrationOpen()) {
                throw ValidationException::withMessages(['registration' => ['Registration is not open for this event.']]);
            }

            $categoryQuery = TicketCategory::query()->where('event_id', $event->id);
            if ($categoryId !== null) {
                $categoryQuery->whereKey($categoryId);
            } else {
                $categoryQuery->where('is_default', true)->where('is_active', true);
            }
            $category = $categoryQuery->lockForUpdate()->first();

            if ($categoryId !== null && ! $category) {
                throw ValidationException::withMessages(['ticket_category_id' => ['Choose an active ticket category for this event.']]);
            }

            if ($category && ! $category->is_active) {
                throw ValidationException::withMessages(['ticket_category_id' => ['This ticket category is no longer available.']]);
            }

            if ($category?->price_minor > 0) {
                throw ValidationException::withMessages(['payment' => ['Paid checkout is not available yet. No ticket was issued.']]);
            }

            if ($user !== null && ! $user->isStudent()) {
                throw ValidationException::withMessages(['registration' => ['Only student accounts can use student registration.']]);
            }

            if (($event->registration_eligibility === Event::ELIGIBILITY_STUDENT
                    || $category?->eligibility === TicketCategory::ELIGIBILITY_STUDENT)
                && ($user === null || ! $this->isVerifiedInstitutionalStudent($user))) {
                throw ValidationException::withMessages([
                    'ticket_category_id' => ['This event or ticket category requires a verified institutional student account.'],
                ]);
            }

            if ($user === null) {
                $guestName = trim((string) $guestName);
                $guestEmail = Str::lower(trim((string) $guestEmail));

                if (! in_array($guestType, ['guest', 'alumni'], true)
                    || $guestName === '' || mb_strlen($guestName) > 255 || str_contains($guestName, "\0")
                    || strlen($guestEmail) > 255 || ! filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([
                        'guest_name' => ['Enter a valid name and email address for the attendee.'],
                    ]);
                }
            } else {
                $guestName = null;
                $guestEmail = null;
                $guestType = 'student';
            }

            if ($this->alreadyRegistered($event->id, $user, $guestEmail)) {
                throw ValidationException::withMessages([
                    'registration' => ['That email or student account already has a registration for this event.'],
                ]);
            }

            $confirmedCount = Registration::query()
                ->where('event_id', $event->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->count();

            if ($confirmedCount >= $event->capacity) {
                throw ValidationException::withMessages(['capacity' => ['Event capacity has been reached.']]);
            }

            if ($category?->capacity !== null) {
                $categoryCount = Registration::query()
                    ->where('ticket_category_id', $category->id)
                    ->where('status', Registration::STATUS_CONFIRMED)
                    ->count();

                if ($categoryCount >= $category->capacity) {
                    throw ValidationException::withMessages(['capacity' => ['This ticket category has sold out.']]);
                }
            }

            $secret = bin2hex(random_bytes(32));
            $registration = Registration::create([
                'event_id' => $event->id,
                'ticket_category_id' => $category?->id,
                'user_id' => $user?->id,
                'attendee_type' => $guestType,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'status' => Registration::STATUS_CONFIRMED,
            ]);

            $ticket = Ticket::create([
                'registration_id' => $registration->id,
                'public_id' => 'ING-'.strtoupper(Str::random(10)),
                'qr_hash' => hash('sha256', $secret),
                'encrypted_secret' => Crypt::encryptString($secret),
                'status' => Ticket::STATUS_ACTIVE,
                'credential_version' => 1,
                'issued_at' => Carbon::now(),
            ]);

            return [
                'registration' => $registration,
                'ticket' => $ticket,
                'qr_payload' => "ingress:v1:{$ticket->public_id}:{$secret}",
            ];
        });
    }

    private function alreadyRegistered(int $eventId, ?User $user, ?string $guestEmail): bool
    {
        $query = Registration::query()->where('event_id', $eventId);

        if ($user !== null) {
            $email = Str::lower(trim($user->email));

            return $query->where(function ($registrations) use ($user, $email): void {
                $registrations->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(guest_email) = ?', [$email]);
            })->exists();
        }

        return $query->where(function ($registrations) use ($guestEmail): void {
            $registrations->whereRaw('LOWER(guest_email) = ?', [$guestEmail])
                ->orWhereHas('user', fn ($users) => $users->whereRaw('LOWER(email) = ?', [$guestEmail]));
        })->exists();
    }

    private function isVerifiedInstitutionalStudent(User $user): bool
    {
        $domain = mb_strtolower(trim((string) config('services.firebase.student_email_domain')));
        $email = mb_strtolower(trim($user->email));

        if (! $user->isStudent() || $user->firebase_uid === null || $user->email_verified_at === null
            || $domain === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return mb_strtolower((string) Str::afterLast($email, '@')) === $domain;
    }
}
