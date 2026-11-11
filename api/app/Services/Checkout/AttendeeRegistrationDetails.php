<?php

namespace App\Services\Checkout;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendeeRegistrationDetails
{
    /** @return array{attendee_type: string, guest_name: ?string, guest_email: ?string} */
    public function prepare(
        Event $event,
        ?TicketCategory $category,
        ?User $user,
        ?string $guestName,
        ?string $guestEmail,
        string $guestType = 'guest',
    ): array {
        if ($user !== null && ! $user->isStudent()) {
            throw ValidationException::withMessages(['registration' => ['Only student accounts can use student registration.']]);
        }

        if (($event->registration_eligibility === Event::ELIGIBILITY_STUDENT
                || $category?->eligibility === TicketCategory::ELIGIBILITY_STUDENT)
            && ! $this->isVerifiedInstitutionalStudent($user)) {
            throw ValidationException::withMessages([
                'ticket_category_id' => ['This event or ticket category requires a verified institutional student account.'],
            ]);
        }

        if ($user !== null) {
            return ['attendee_type' => 'student', 'guest_name' => null, 'guest_email' => null];
        }

        $guestName = trim((string) $guestName);
        $guestEmail = Str::lower(trim((string) $guestEmail));

        if (! in_array($guestType, ['guest', 'alumni'], true)
            || $guestName === '' || mb_strlen($guestName) > 255 || str_contains($guestName, "\0")
            || strlen($guestEmail) > 255 || ! filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'guest_name' => ['Enter a valid name and email address for the attendee.'],
            ]);
        }

        return ['attendee_type' => $guestType, 'guest_name' => $guestName, 'guest_email' => $guestEmail];
    }

    public function assertIdentityAvailable(
        int $eventId,
        ?User $user,
        ?string $guestEmail,
        ?int $ignoreCheckoutOrderId = null,
        ?int $ignoreRegistrationId = null,
    ): void {
        $registrations = Registration::query()->where('event_id', $eventId)
            ->when($ignoreRegistrationId !== null, fn ($query) => $query->whereKeyNot($ignoreRegistrationId));
        $orders = CheckoutOrder::query()->where('event_id', $eventId)->activeReservation()
            ->when($ignoreCheckoutOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreCheckoutOrderId));

        if ($user !== null) {
            $email = Str::lower(trim($user->email));
            $registered = $registrations->where(function ($query) use ($user, $email): void {
                $query->where('user_id', $user->id)->orWhereRaw('LOWER(guest_email) = ?', [$email]);
            })->exists();
            $reserved = $orders->where(function ($query) use ($user, $email): void {
                $query->where('user_id', $user->id)->orWhereRaw('LOWER(guest_email) = ?', [$email]);
            })->exists();
        } else {
            $email = Str::lower(trim((string) $guestEmail));
            $registered = $registrations->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(guest_email) = ?', [$email])
                    ->orWhereHas('user', fn ($users) => $users->whereRaw('LOWER(email) = ?', [$email]));
            })->exists();
            $reserved = $orders->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(guest_email) = ?', [$email])
                    ->orWhereHas('user', fn ($users) => $users->whereRaw('LOWER(email) = ?', [$email]));
            })->exists();
        }

        if ($registered || $reserved) {
            throw ValidationException::withMessages([
                'registration' => ['That email or student account already has a registration or active checkout for this event.'],
            ]);
        }
    }

    private function isVerifiedInstitutionalStudent(?User $user): bool
    {
        $domain = mb_strtolower(trim((string) config('services.firebase.student_email_domain')));
        $email = mb_strtolower(trim((string) $user?->email));

        if ($user === null || ! $user->isStudent() || $user->firebase_uid === null || $user->email_verified_at === null
            || $domain === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return mb_strtolower((string) Str::afterLast($email, '@')) === $domain;
    }
}
