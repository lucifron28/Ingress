<?php

namespace App\Actions;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\PaymentAttempt;
use App\Models\TicketCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BeginSimulatedPaymentAttempt
{
    public function __construct(private ExpireCheckoutReservations $expiration) {}

    public function execute(int $orderId, string $method, string $attemptKey, string $simulatedOutcome): ?PaymentAttempt
    {
        if (! in_array($simulatedOutcome, [PaymentAttempt::SIMULATED_OUTCOME_SUCCESS, PaymentAttempt::SIMULATED_OUTCOME_FAILURE], true)) {
            throw ValidationException::withMessages(['simulated_outcome' => ['Choose a simulated success or failure outcome.']]);
        }

        return DB::transaction(function () use ($orderId, $method, $attemptKey, $simulatedOutcome): ?PaymentAttempt {
            $eventId = CheckoutOrder::query()->whereKey($orderId)->value('event_id');
            $event = $eventId === null ? null : Event::query()->whereKey($eventId)->lockForUpdate()->first();
            if (! $event) {
                return null;
            }

            $this->expiration->forLockedEvent($event);
            TicketCategory::query()->where('event_id', $event->id)
                ->whereKey(CheckoutOrder::query()->whereKey($orderId)->value('ticket_category_id'))
                ->lockForUpdate()->first();
            $order = CheckoutOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! $event->canOfferSimulatedPayments() || ! $event->isRegistrationOpen()) {
                if (in_array($order->status, CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true)) {
                    $order->update(['status' => CheckoutOrder::STATUS_CANCELLED]);
                    $order->attempts()->where('status', PaymentAttempt::STATUS_PENDING)->update([
                        'status' => PaymentAttempt::STATUS_CANCELLED,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return null;
            }

            if (! in_array($order->status, CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true)
                || $order->reservation_expires_at->lte(now())) {
                return null;
            }

            $existing = $order->attempts()->where('attempt_key', $attemptKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $pending = $order->attempts()->where('status', PaymentAttempt::STATUS_PENDING)->lockForUpdate()->first();
            if ($pending) {
                return $pending;
            }

            $attemptNumber = ((int) $order->attempts()->max('attempt_number')) + 1;
            $attempt = $order->attempts()->create([
                'attempt_number' => $attemptNumber,
                'attempt_key' => $attemptKey,
                'payment_method' => $method,
                'simulated_outcome' => $simulatedOutcome,
                'status' => PaymentAttempt::STATUS_PENDING,
            ]);
            $order->update(['status' => CheckoutOrder::STATUS_PENDING]);

            return $attempt;
        });
    }
}
