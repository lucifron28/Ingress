<?php

namespace App\Actions;

use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;

class RegisterForEvent
{
    /**
     * Register an authenticated student using the event's default ticket category.
     *
     * @return array{registration: Registration, ticket: Ticket, qr_payload: string, redemption_code: ?string}
     */
    public function execute(User $user, int $eventId): array
    {
        return app(RegisterAttendeeForEvent::class)->execute($eventId, null, $user);
    }
}
