<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketController extends Controller
{
    /**
     * Get all tickets belonging to the authenticated attendee.
     */
    public function myTickets(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $tickets = Ticket::with(['registration.event.venue', 'registration.user'])
            ->whereHas('registration', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('issued_at', 'desc')
            ->get();

        return TicketResource::collection($tickets);
    }

    /**
     * Get ticket details.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $ticket->load(['registration.event.venue', 'registration.user']);

        // Access control: Only attendee owner, the organizer of this event, or an admin
        $isOwner = $ticket->registration && (int) $ticket->registration->user_id === (int) $user->id;
        $isEventOrganizer = $ticket->registration
            && $ticket->registration->event
            && (int) $ticket->registration->event->organizer_id === (int) $user->id;
        $isAdmin = $user->isAdmin();

        if (! $isOwner && ! $isEventOrganizer && ! $isAdmin) {
            return response()->json([
                'message' => 'Unauthorized access to ticket details.',
            ], 403);
        }
        return response()->json([
            'ticket' => new TicketResource($ticket),
        ]);
    }
}
