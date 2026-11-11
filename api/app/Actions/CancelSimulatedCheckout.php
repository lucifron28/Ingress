<?php

namespace App\Actions;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\PaymentAttempt;
use App\Models\TicketCategory;
use Illuminate\Support\Facades\DB;

class CancelSimulatedCheckout
{
    public function __construct(private ExpireCheckoutReservations $expiration) {}

    public function execute(int $orderId): CheckoutOrder
    {
        return DB::transaction(function () use ($orderId): CheckoutOrder {
            $eventId = CheckoutOrder::query()->whereKey($orderId)->value('event_id');
            $event = Event::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            $this->expiration->forLockedEvent($event);
            TicketCategory::query()->where('event_id', $event->id)
                ->whereKey(CheckoutOrder::query()->whereKey($orderId)->value('ticket_category_id'))
                ->lockForUpdate()->first();
            $order = CheckoutOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (in_array($order->status, CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true)) {
                $order->update(['status' => CheckoutOrder::STATUS_CANCELLED]);
                $order->attempts()->where('status', PaymentAttempt::STATUS_PENDING)->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $order->refresh();
        });
    }
}
