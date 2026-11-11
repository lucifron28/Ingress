<?php

namespace App\Actions;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\PaymentAttempt;
use App\Models\Registration;
use App\Models\TicketCategory;
use App\Services\Checkout\AttendeeRegistrationDetails;
use App\Services\Checkout\CheckoutCapacity;
use App\Services\Checkout\SimulatedPaymentProcessor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessSimulatedPayment
{
    public function __construct(
        private ExpireCheckoutReservations $expiration,
        private AttendeeRegistrationDetails $attendeeDetails,
        private CheckoutCapacity $capacity,
        private IssueRegistrationTicket $ticketIssuer,
        private SimulatedPaymentProcessor $processor,
    ) {}

    /** @return array{status: string, order: CheckoutOrder, registration: ?Registration, redemption_code: ?string} */
    public function execute(int $orderId, string $attemptKey): array
    {
        return DB::transaction(function () use ($orderId, $attemptKey): array {
            $eventId = CheckoutOrder::query()->whereKey($orderId)->value('event_id');
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            $this->expiration->forLockedEvent($event);
            $categoryId = CheckoutOrder::query()->whereKey($orderId)->value('ticket_category_id');
            $category = TicketCategory::query()->where('event_id', $event->id)
                ->whereKey($categoryId)->lockForUpdate()->firstOrFail();
            $order = CheckoutOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status === CheckoutOrder::STATUS_SUCCESSFUL) {
                return $this->result($order, $order->registration, null);
            }

            if (! in_array($order->status, CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true)) {
                return $this->result($order, null, null);
            }

            $attempt = $order->attempts()->where('attempt_key', $attemptKey)->lockForUpdate()->first();
            if (! $attempt) {
                throw ValidationException::withMessages(['attempt_key' => ['This payment attempt is not available.']]);
            }

            if ($attempt->status !== PaymentAttempt::STATUS_PENDING) {
                return $this->result($order, $order->registration, null);
            }

            if (! $event->canOfferSimulatedPayments() || ! $event->isRegistrationOpen()) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'outcome_code' => 'checkout_unavailable',
                    'processed_at' => now(),
                ]);
                $order->update(['status' => CheckoutOrder::STATUS_CANCELLED]);

                return $this->result($order->refresh(), null, null);
            }

            if (! $category->is_active || $category->price_minor !== $order->amount_minor
                || $category->currency !== $order->currency) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_FAILED,
                    'outcome_code' => 'category_changed',
                    'processed_at' => now(),
                ]);
                $order->update(['status' => CheckoutOrder::STATUS_FAILED]);

                return $this->result($order->refresh(), null, null);
            }

            $outcome = $this->processor->process($attempt);
            if ($outcome !== PaymentAttempt::STATUS_SUCCESSFUL) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_FAILED,
                    'outcome_code' => 'simulated_decline',
                    'processed_at' => now(),
                ]);
                $order->update(['status' => CheckoutOrder::STATUS_FAILED]);

                return $this->result($order->refresh(), null, null);
            }

            $user = $order->user;
            try {
                $details = $this->attendeeDetails->prepare(
                    $event,
                    $category,
                    $user,
                    $order->guest_name,
                    $order->guest_email,
                    $order->attendee_type,
                );
                $this->attendeeDetails->assertIdentityAvailable($event->id, $user, $details['guest_email'], $order->id);
                $this->capacity->assertAvailable($event, $category, $order->id);
            } catch (ValidationException) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_FAILED,
                    'outcome_code' => 'reservation_unavailable',
                    'processed_at' => now(),
                ]);
                $order->update(['status' => CheckoutOrder::STATUS_FAILED]);

                return $this->result($order->refresh(), null, null);
            }

            $issued = $this->ticketIssuer->execute($event, $category, $user, $details);
            $redemptionCodeCiphertext = is_string($issued['redemption_code'])
                ? Crypt::encryptString($issued['redemption_code'])
                : null;
            $order->update([
                'registration_id' => $issued['registration']->id,
                'status' => CheckoutOrder::STATUS_SUCCESSFUL,
                'redemption_code_ciphertext' => $redemptionCodeCiphertext,
                'redemption_code_expires_at' => $redemptionCodeCiphertext === null
                    ? null
                    : now()->addMinutes(15),
                'completed_at' => now(),
            ]);
            $attempt->update([
                'status' => PaymentAttempt::STATUS_SUCCESSFUL,
                'outcome_code' => 'simulated_approved',
                'processed_at' => now(),
            ]);

            return $this->result($order->refresh(), $issued['registration'], $issued['redemption_code']);
        });
    }

    /** @return array{status: string, order: CheckoutOrder, registration: ?Registration, redemption_code: ?string} */
    private function result(CheckoutOrder $order, ?Registration $registration, ?string $redemptionCode): array
    {
        return [
            'status' => $order->status,
            'order' => $order,
            'registration' => $registration,
            'redemption_code' => $redemptionCode,
        ];
    }
}
