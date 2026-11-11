<?php

namespace App\Services\Tickets;

use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Checkout\AttendeeRegistrationDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestTicketTransfer
{
    private const INVALID_CODE_MESSAGE = 'This code is invalid or the ticket cannot be transferred.';

    public function __construct(
        private TicketCredentialRotation $credentialRotation,
        private AttendeeRegistrationDetails $attendeeDetails,
    ) {}

    /**
     * @return array{registration: Registration, ticket: Ticket, redemption_code: string}
     */
    public function redeem(string $redemptionCode, string $guestName, string $guestEmail): array
    {
        $guestName = trim($guestName);
        $guestEmail = Str::lower(trim($guestEmail));

        if (! preg_match('/\A[a-f0-9]{64}\z/', $redemptionCode)
            || $guestName === ''
            || mb_strlen($guestName) > 255
            || str_contains($guestName, "\0")
            || strlen($guestEmail) > 255
            || filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->reject();
        }

        $redemptionHash = hash('sha256', $redemptionCode);
        $candidate = Ticket::query()
            ->where('redemption_hash', $redemptionHash)
            ->first(['id', 'registration_id']);
        if (! $candidate) {
            $this->reject();
        }

        $eventId = Registration::query()->whereKey($candidate->registration_id)->value('event_id');
        if (! $eventId) {
            $this->reject();
        }

        return DB::transaction(function () use ($candidate, $eventId, $redemptionHash, $guestName, $guestEmail): array {
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();
            $ticket = Ticket::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $registration = $ticket
                ? Registration::query()->whereKey($ticket->registration_id)->lockForUpdate()->first()
                : null;

            if (! $event || ! $ticket || ! $registration
                || (int) $registration->event_id !== (int) $event->id
                || ! is_string($ticket->redemption_hash)
                || ! hash_equals($ticket->redemption_hash, $redemptionHash)) {
                $this->reject();
            }

            $category = $registration->ticket_category_id === null
                ? null
                : TicketCategory::query()->whereKey($registration->ticket_category_id)->lockForUpdate()->first();
            if ($registration->ticket_category_id !== null && ! $category) {
                $this->reject();
            }

            $this->assertTransferable($event, $registration, $ticket, $category);
            $this->attendeeDetails->assertIdentityAvailable(
                (int) $event->id,
                null,
                $guestEmail,
                ignoreRegistrationId: (int) $registration->id,
            );
            $registration->forceFill([
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
            ])->save();
            $newCode = $this->credentialRotation->rotateGuestCredentials($event, $ticket, 'redeemed');

            return [
                'registration' => $registration,
                'ticket' => $ticket,
                'redemption_code' => $newCode,
            ];
        });
    }

    /**
     * @return array{registration: Registration, ticket: Ticket, redemption_code: string}
     */
    public function recover(int $eventId, int $registrationId, User $actor): array
    {
        if (config('ingress.mode') !== 'cloud') {
            $this->rejectRecovery();
        }

        return DB::transaction(function () use ($eventId, $registrationId, $actor): array {
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();
            if (! $event) {
                $this->rejectRecovery();
            }
            abort_unless($actor->can('update', $event), 403);

            $ticketId = Ticket::query()->where('registration_id', $registrationId)->value('id');
            $ticket = $ticketId ? Ticket::query()->whereKey($ticketId)->lockForUpdate()->first() : null;
            $registration = $ticket
                ? Registration::query()->whereKey($ticket->registration_id)->lockForUpdate()->first()
                : null;
            if (! $ticket || ! $registration || (int) $registration->event_id !== (int) $event->id) {
                $this->rejectRecovery();
            }

            $category = $registration->ticket_category_id === null
                ? null
                : TicketCategory::query()->whereKey($registration->ticket_category_id)->lockForUpdate()->first();
            if ($registration->ticket_category_id !== null && ! $category) {
                $this->rejectRecovery();
            }

            $this->assertTransferable($event, $registration, $ticket, $category, recovery: true);
            $newCode = $this->credentialRotation->rotateGuestCredentials(
                $event,
                $ticket,
                'recovery',
                $actor->id,
            );

            return [
                'registration' => $registration,
                'ticket' => $ticket,
                'redemption_code' => $newCode,
            ];
        });
    }

    private function assertTransferable(
        Event $event,
        Registration $registration,
        Ticket $ticket,
        ?TicketCategory $category,
        bool $recovery = false,
    ): void {
        if (config('ingress.mode') !== 'cloud'
            || ! $event->canTransferTickets()
            || $event->registration_eligibility !== 'public'
            || ! in_array($registration->attendee_type, ['guest', 'alumni'], true)
            || $registration->user_id !== null
            || $registration->status !== Registration::STATUS_CONFIRMED
            || ! $ticket->isActive()
            || (int) $ticket->credential_version < 1
            || ($category !== null && ((int) $category->event_id !== (int) $event->id
                || $category->eligibility !== TicketCategory::ELIGIBILITY_PUBLIC))
            || Admission::query()->where('ticket_id', $ticket->id)->exists()) {
            if ($recovery) {
                $this->rejectRecovery();
            }

            $this->reject();
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'redemption_code' => [self::INVALID_CODE_MESSAGE],
        ]);
    }

    private function rejectRecovery(): never
    {
        throw ValidationException::withMessages([
            'guest' => ['This ticket is not eligible for transfer-code recovery.'],
        ]);
    }
}
