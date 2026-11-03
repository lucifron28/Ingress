<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdmitTicket;
use App\Actions\NodeAdmissionAccess;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAdmissionController extends Controller
{
    /**
     * List events assigned to the authenticated staff member.
     */
    public function assignedEvents(Request $request): JsonResponse
    {
        $user = $request->user();

        if (config('ingress.mode') === 'node') {
            $events = Event::with(['venue', 'accessPoints', 'staffAssignments'])
                ->whereHas('staffAssignments', fn ($query) => $query->where('user_id', $user->id))
                ->published()->orderBy('starts_at', 'asc')->get()
                ->filter(fn ($event) => app(NodeAdmissionAccess::class)->allows($user, $event->id))->values();
        } elseif ($user->isAdmin()) {
            $events = Event::with(['venue', 'accessPoints'])
                ->published()
                ->orderBy('starts_at', 'asc')
                ->get();
        } else {
            $events = Event::with(['venue', 'accessPoints', 'staffAssignments'])
                ->where(function ($query) use ($user) {
                    $query->whereHas('staffAssignments', fn ($q) => $q->where('user_id', $user->id))
                        ->orWhere('organizer_id', $user->id);
                })
                ->published()
                ->orderBy('starts_at', 'asc')
                ->get();
        }

        return response()->json([
            'events' => $events->map(function ($event) use ($user) {
                $isSuper = config('ingress.mode') !== 'node' && ($user->isAdmin() || $event->organizer_id === $user->id);
                $assignment = $event->staffAssignments?->firstWhere('user_id', $user->id);

                $gates = $event->accessPoints->where('is_active', true);
                if (! $isSuper && $assignment && $assignment->access_point_id !== null) {
                    $gates = $gates->where('id', $assignment->access_point_id);
                }

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'venue' => $event->venue ? [
                        'name' => $event->venue->name,
                        'location' => $event->venue->location,
                    ] : null,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'access_points' => $gates->values()->map(fn ($ap) => [
                        'id' => $ap->id,
                        'name' => $ap->name,
                        'code' => $ap->code,
                    ]),
                ];
            }),
        ]);
    }

    /**
     * Get access points for an event.
     */
    public function accessPoints(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        $isSuper = config('ingress.mode') !== 'node' && ($user->isAdmin() || $event->organizer_id === $user->id);
        $assignment = $event->staffAssignments()->where('user_id', $user->id)->first();

        if (config('ingress.mode') === 'node' && ! app(NodeAdmissionAccess::class)->allows($user, $event->id)) {
            return response()->json(['message' => 'Node authority or staff session is unavailable.'], 403);
        }
        if (! $isSuper && ! $assignment) {
            return response()->json([
                'message' => 'Unauthorized. You are not assigned to this event.',
            ], 403);
        }

        $query = $event->accessPoints()->where('is_active', true);

        if (! $isSuper && $assignment->access_point_id !== null) {
            $query->where('id', $assignment->access_point_id);
        }

        return response()->json([
            'access_points' => $query->get()->map(fn ($ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'code' => $ap->code,
            ]),
        ]);
    }

    /**
     * Read-only preview of an entrance ticket scan before admission confirmation.
     */
    public function preview(Request $request, Event $event, AdmitTicket $admitTicket): JsonResponse
    {
        $validated = $request->validate([
            'access_point_id' => ['required', 'integer'],
            'qr_payload' => ['required', 'string'],
        ]);

        $result = $admitTicket->preview(
            staff: $request->user(),
            eventId: $event->id,
            accessPointId: $validated['access_point_id'],
            qrPayload: $validated['qr_payload']
        );

        $httpCode = $result['http_code'] ?? 200;
        unset($result['http_code']);

        return response()->json($result, $httpCode);
    }

    /**
     * Process entrance ticket scan.
     */
    public function scan(Request $request, Event $event, AdmitTicket $admitTicket): JsonResponse
    {
        $validated = $request->validate([
            'access_point_id' => ['required', 'integer'],
            'qr_payload' => ['required', 'string'],
            'client_scan_id' => ['required', 'string', 'max:64'],
        ]);

        $result = $admitTicket->execute(
            staff: $request->user(),
            eventId: $event->id,
            accessPointId: $validated['access_point_id'],
            qrPayload: $validated['qr_payload'],
            clientScanId: $validated['client_scan_id']
        );

        $httpCode = $result['http_code'] ?? 200;
        unset($result['http_code']);

        return response()->json($result, $httpCode);
    }
}
