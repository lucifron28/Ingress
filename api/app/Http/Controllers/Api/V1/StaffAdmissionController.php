<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdmitTicket;
use App\Actions\IssueNodeWalkIn;
use App\Actions\NodeAdmissionAccess;
use App\Actions\RecoverNodeWalkIn;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAdmissionController extends Controller
{
    public function issueWalkIn(Request $request, Event $event, IssueNodeWalkIn $issue): JsonResponse
    {
        $input = $request->validate([
            'client_request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'kind' => ['required', 'in:student,guest,alumni'],
            'student_id' => ['nullable', 'string', 'max:255'],
        ]);
        $ticket = $issue->execute($request->user(), $event->id, $input['client_request_id'], $input['name'],
            $input['email'], $input['kind'], $input['student_id'] ?? null);

        return $this->walkInPass($ticket);
    }

    public function recoverWalkIn(Request $request, Event $event, RecoverNodeWalkIn $recover): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return $this->walkInPass($recover->execute($request->user(), $event->id, $input['email']));
    }

    private function walkInPass(Ticket $ticket): JsonResponse
    {
        $payload = $ticket->getQrPayload();
        abort_if($payload === null, 500, 'The ticket credential is unavailable.');

        return response()->json(['public_id' => $ticket->public_id, 'qr_payload' => $payload,
            'attendee_name' => $ticket->registration->attendeeName(), 'student_id' => $ticket->registration->attendeeStudentId()])
            ->header('Cache-Control', 'no-store, private');
    }

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
