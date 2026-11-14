<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Auth\StudentEmailEligibility;
use App\Services\Checkout\AttendeeRegistrationDetails;
use App\Services\Checkout\CheckoutCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterAttendeeForEvent
{
    public function __construct(
        private IssueRegistrationTicket $ticketIssuer,
        private AttendeeRegistrationDetails $attendeeDetails,
        private CheckoutCapacity $capacity,
        private ExpireCheckoutReservations $expiration,
        private StudentEmailEligibility $studentEmailEligibility,
    ) {}

    /**
     * Register one attendee under the event lock shared by student and guest paths.
     *
     * @return array{registration: Registration, ticket: Ticket, qr_payload: string, redemption_code: ?string}
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
            if ($user !== null) {
                $this->studentEmailEligibility->lockPolicyForUpdate();
            }
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();

            if (! $event) {
                throw ValidationException::withMessages(['event' => ['Event does not exist.']]);
            }

            if (! $event->isRegistrationOpen()) {
                throw ValidationException::withMessages(['registration' => ['Registration is not open for this event.']]);
            }

            $this->expiration->forLockedEvent($event);

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

            $details = $this->attendeeDetails->prepare($event, $category, $user, $guestName, $guestEmail, $guestType);
            $this->attendeeDetails->assertIdentityAvailable($event->id, $user, $details['guest_email']);
            $this->capacity->assertAvailable($event, $category);

            return $this->ticketIssuer->execute($event, $category, $user, $details);
        });
    }
}
