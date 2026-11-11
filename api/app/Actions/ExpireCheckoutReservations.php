<?php

namespace App\Actions;

use App\Models\CheckoutOrder;
use App\Models\Event;

class ExpireCheckoutReservations
{
    /** The caller must hold the event row lock before expiring its orders. */
    public function forLockedEvent(Event $event): void
    {
        $orders = CheckoutOrder::query()
            ->where('event_id', $event->id)
            ->whereIn('status', CheckoutOrder::ACTIVE_RESERVATION_STATUSES)
            ->where('reservation_expires_at', '<=', now())
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            $order->update(['status' => CheckoutOrder::STATUS_EXPIRED]);
            $order->attempts()
                ->where('status', 'pending')
                ->update(['status' => 'expired', 'processed_at' => now(), 'updated_at' => now()]);
        }
    }
}
