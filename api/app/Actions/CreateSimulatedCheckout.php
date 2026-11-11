<?php

namespace App\Actions;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Checkout\AttendeeRegistrationDetails;
use App\Services\Checkout\CheckoutCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSimulatedCheckout
{
    public function __construct(
        private AttendeeRegistrationDetails $attendeeDetails,
        private CheckoutCapacity $capacity,
        private ExpireCheckoutReservations $expiration,
    ) {}

    public function execute(
        int $eventId,
        int $categoryId,
        ?User $user,
        ?string $guestName,
        ?string $guestEmail,
        string $idempotencyKey,
        string $guestType = 'guest',
    ): CheckoutOrder {
        return DB::transaction(function () use (
            $eventId, $categoryId, $user, $guestName, $guestEmail, $idempotencyKey, $guestType,
        ): CheckoutOrder {
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();

            if (! $event) {
                throw ValidationException::withMessages(['event' => ['Event does not exist.']]);
            }

            $this->expiration->forLockedEvent($event);
            $category = TicketCategory::query()->where('event_id', $event->id)
                ->whereKey($categoryId)->lockForUpdate()->first();

            if (! $category) {
                throw ValidationException::withMessages(['ticket_category_id' => ['Choose a ticket category for this event.']]);
            }

            $details = $this->attendeeDetails->prepare($event, $category, $user, $guestName, $guestEmail, $guestType);
            $existingOrder = CheckoutOrder::query()
                ->where('event_id', $event->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingOrder) {
                if (! $this->matchesRequest($existingOrder, $category, $user, $details)) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This checkout request has already been used.']]);
                }

                return $existingOrder;
            }

            if (! $event->isRegistrationOpen()) {
                throw ValidationException::withMessages(['registration' => ['Registration is not open for this event.']]);
            }

            if (! $event->canOfferSimulatedPayments()) {
                throw ValidationException::withMessages(['payment' => ['Simulated paid checkout is not authorized for this event.']]);
            }

            if (! $category->is_active || $category->price_minor <= 0) {
                throw ValidationException::withMessages(['ticket_category_id' => ['Choose an active paid ticket category for this event.']]);
            }

            $this->attendeeDetails->assertIdentityAvailable($event->id, $user, $details['guest_email']);
            $this->capacity->assertAvailable($event, $category);

            return CheckoutOrder::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'ticket_category_id' => $category->id,
                'user_id' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                'attendee_type' => $details['attendee_type'],
                'guest_name' => $details['guest_name'],
                'guest_email' => $details['guest_email'],
                'amount_minor' => $category->price_minor,
                'currency' => $category->currency,
                'status' => CheckoutOrder::STATUS_PENDING,
                'reservation_expires_at' => now()->addMinutes(max(1, (int) config('ingress.simulated_payments.reservation_minutes', 10))),
            ]);
        });
    }

    /** @param array{attendee_type: string, guest_name: ?string, guest_email: ?string} $details */
    private function matchesRequest(CheckoutOrder $order, TicketCategory $category, ?User $user, array $details): bool
    {
        return $order->ticket_category_id === $category->id
            && $order->user_id === $user?->id
            && $order->attendee_type === $details['attendee_type']
            && $order->guest_name === $details['guest_name']
            && $order->guest_email === $details['guest_email'];
    }
}
