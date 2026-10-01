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

        // Check ownership or staff privileges
        $isOwner = $ticket->registration && $ticket->registration->user_id === $user->id;
        if (! $isOwner && ! $user->isStaff() && ! $user->isOrganizer() && ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized access to ticket details.',
            ], 403);
        }

        return response()->json([
            'ticket' => new TicketResource($ticket),
        ]);
    }
}
