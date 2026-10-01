<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterForEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    /**
     * Register authenticated attendee for an event.
     */
    public function store(Request $request, Event $event, RegisterForEvent $action): JsonResponse
    {
        $result = $action->execute($request->user(), $event->id);

        return response()->json([
            'message' => 'Registration confirmed and ticket issued.',
            'registration_id' => $result['registration']->id,
            'ticket' => new TicketResource($result['ticket']),
            'qr_payload' => $result['qr_payload'],
        ], 201);
    }

    /**
     * Cancel registration and revoke ticket.
     */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();

        $registration = Registration::where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->first();

        if (! $registration) {
            throw ValidationException::withMessages([
                'registration' => ['No active confirmed registration found for this event.'],
            ]);
        }

        if (Carbon::now()->gt($event->starts_at)) {
            throw ValidationException::withMessages([
                'registration' => ['Cannot cancel registration after event has commenced.'],
            ]);
        }

        // Cancel inside one transaction that locks the ticket row first — the same
        // order the admission transaction uses. Without this, the two statements
        // below autocommit separately, so a scan could read a still-confirmed
        // registration and insert an admission in between, leaving a cancelled
        // registration with a current-credential admission.
        $cancelledAt = Carbon::now();

        DB::transaction(function () use ($registration, $cancelledAt) {
            $ticketId = $registration->ticket?->id;
            $lockedTicket = $ticketId
                ? Ticket::whereKey($ticketId)->lockForUpdate()->first()
                : null;

            // Re-read under the lock: a concurrent request may have changed it.
            $lockedRegistration = Registration::whereKey($registration->getKey())
                ->where('status', Registration::STATUS_CONFIRMED)
                ->first();

            if (! $lockedRegistration) {
                throw ValidationException::withMessages([
                    'registration' => ['No active confirmed registration found for this event.'],
                ]);
            }

            $lockedRegistration->update([
                'status' => Registration::STATUS_CANCELLED,
                'cancelled_at' => $cancelledAt,
            ]);

            $lockedTicket?->update([
                'status' => Ticket::STATUS_REVOKED,
                'revoked_at' => $cancelledAt,
            ]);
        });

        return response()->json([
            'message' => 'Registration cancelled and digital ticket revoked.',
        ]);
    }
}
