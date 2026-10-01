<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    /**
     * List all published upcoming events for mobile discovery.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = Event::with('venue')
            ->published()
            ->upcoming()
            ->orderBy('starts_at', 'asc')
            ->get();

        return EventResource::collection($events);
    }

    /**
     * Show single event details for mobile attendee.
     */
    public function show(Request $request, Event $event): JsonResponse
    {
        // Allow public viewing if published, or organizer/admin if draft
        if (! $event->isPublished()) {
            $user = $request->user();
            if (! $user || (! $user->isAdmin() && $user->id !== $event->organizer_id)) {
                return response()->json([
                    'message' => 'Event not found or not currently published.',
                ], 404);
            }
        }

        $event->load('venue');

        return response()->json([
            'event' => new EventResource($event),
        ]);
    }
}
