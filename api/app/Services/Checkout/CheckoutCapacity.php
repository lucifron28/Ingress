<?php

namespace App\Services\Checkout;

use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketCategory;
use Illuminate\Validation\ValidationException;

class CheckoutCapacity
{
    public function eventOccupancy(Event $event, ?int $ignoreOrderId = null): int
    {
        $confirmed = Registration::query()->where('event_id', $event->id)
            ->where('status', Registration::STATUS_CONFIRMED)->count();
        $reserved = CheckoutOrder::query()->where('event_id', $event->id)->activeReservation()
            ->when($ignoreOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreOrderId))->count();

        return $confirmed + $reserved;
    }

    public function categoryOccupancy(TicketCategory $category, ?int $ignoreOrderId = null): int
    {
        $confirmed = Registration::query()->where('ticket_category_id', $category->id)
            ->where('status', Registration::STATUS_CONFIRMED)->count();
        $reserved = CheckoutOrder::query()->where('ticket_category_id', $category->id)->activeReservation()
            ->when($ignoreOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreOrderId))->count();

        return $confirmed + $reserved;
    }

    public function assertAvailable(Event $event, ?TicketCategory $category, ?int $ignoreOrderId = null): void
    {
        if ($this->eventOccupancy($event, $ignoreOrderId) >= $event->capacity) {
            throw ValidationException::withMessages(['capacity' => ['Event capacity has been reached.']]);
        }

        if ($category?->capacity === null) {
            return;
        }

        if ($this->categoryOccupancy($category, $ignoreOrderId) >= $category->capacity) {
            throw ValidationException::withMessages(['capacity' => ['This ticket category has sold out.']]);
        }
    }
}
