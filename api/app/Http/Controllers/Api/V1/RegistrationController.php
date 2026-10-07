<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterForEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    /**
     * Register authenticated attendee for an event.
     */
    public function store(Request $request, Event $event, RegisterForEvent $action): JsonResponse
    {
        if (! $request->user()->isStudent()) {
            return response()->json([
                'message' => 'Only students are permitted to self-register for events.',
            ], 403);
        }

        $result = $action->execute($request->user(), $event->id);

        return response()->json([
            'message' => 'Registration confirmed and ticket issued.',
            'registration_id' => $result['registration']->id,
            'ticket' => new TicketResource($result['ticket']),
            'qr_payload' => $result['qr_payload'],
        ], 201);
    }

}
