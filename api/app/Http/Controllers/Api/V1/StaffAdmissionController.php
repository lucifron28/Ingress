<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdmitTicket;
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

        if ($user->isAdmin()) {
            $events = Event::with(['venue', 'accessPoints'])
                ->published()
                ->orderBy('starts_at', 'asc')
                ->get();
        } else {
            $events = Event::with(['venue', 'accessPoints'])
                ->whereHas('staffAssignments', fn ($q) => $q->where('user_id', $user->id))
                ->published()
                ->orderBy('starts_at', 'asc')
                ->get();
        }

        return response()->json([
            'events' => $events->map(fn ($event) => [
                'id' => $event->id,
                'title' => $event->title,
                'venue' => $event->venue ? [
                    'name' => $event->venue->name,
                    'location' => $event->venue->location,
                ] : null,
                'starts_at' => $event->starts_at?->toISOString(),
                'ends_at' => $event->ends_at?->toISOString(),
                'access_points' => $event->accessPoints->where('is_active', true)->values()->map(fn ($ap) => [
                    'id' => $ap->id,
                    'name' => $ap->name,
                    'code' => $ap->code,
                ]),
            ]),
        ]);
    }

    /**
     * Get access points for an event.
     */
    public function accessPoints(Request $request, Event $event): JsonResponse
    {
        return response()->json([
            'access_points' => $event->accessPoints()->where('is_active', true)->get()->map(fn ($ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'code' => $ap->code,
            ]),
        ]);
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
