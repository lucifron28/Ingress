<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizerAttendanceController extends Controller
{
    /**
     * Show real-time event attendance command center.
     */
    public function show(Request $request, Event $event): View
    {
        Gate::authorize('update', $event);
        $event->load(['venue', 'accessPoints']);

        $stats = $this->calculateMetrics($event);
        $gateStats = $this->calculateGateStats($event);
        $recentAdmissions = $this->getRecentAdmissions($event);

        // Attendee Directory Query
        $query = Registration::with(['user', 'ticket.latestAdmission.accessPoint'])
            ->where('event_id', $event->id)
            ->where('status', Registration::STATUS_CONFIRMED);

        if ($search = $request->input('search')) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('user', function ($user) use ($search) {
                    $user->whereLike('name', "%{$search}%")
                        ->orWhereLike('email', "%{$search}%")
                        ->orWhereLike('student_id', "%{$search}%");
                })->orWhereLike('guest_name', "%{$search}%")
                    ->orWhereLike('guest_email', "%{$search}%")
                    ->orWhereLike('guest_student_id', "%{$search}%");
            });
        }

        if ($filter = $request->input('status')) {
            if ($filter === 'checked_in') {
                $query->whereHas('ticket.latestAdmission', fn ($movement) => $movement->where('direction', Admission::DIRECTION_ENTRY));
            } elseif ($filter === 'outside') {
                $query->whereHas('ticket.latestAdmission', fn ($movement) => $movement->where('direction', Admission::DIRECTION_EXIT));
            } elseif ($filter === 'not_arrived') {
                $query->whereDoesntHave('ticket.admissions');
            }
        }

        $attendees = $query->orderBy('created_at', 'asc')->paginate(25)->withQueryString();

        return view('organizer.attendance', compact(
            'event',
            'stats',
            'gateStats',
            'recentAdmissions',
            'attendees'
        ));
    }

    /**
     * JSON feed for live attendance polling without full page reload.
     */
    public function feed(Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        $stats = $this->calculateMetrics($event);
        $gateStats = $this->calculateGateStats($event);
        $recent = $this->getRecentAdmissions($event)->map(fn ($adm) => [
            'id' => $adm->id,
            'attendee_name' => $adm->ticket?->registration?->attendeeName() ?? 'Attendee',
            'student_id' => $adm->ticket?->registration?->attendeeStudentId() ?? 'N/A',
            'access_point' => $adm->accessPoint?->name ?? 'Access Point',
            'direction' => $adm->direction,
            'admitted_at' => $adm->admitted_at->format('g:i:s A'),
            'scanned_by' => $adm->scannedBy?->name ?? 'Staff',
        ]);

        return response()->json([
            'stats' => $stats,
            'gate_stats' => $gateStats,
            'recent_admissions' => $recent,
        ]);
    }

    /**
     * Compute KPI statistics.
     *
     * @return array{
     *     total_capacity: int,
     *     registered_count: int,
     *     checked_in_count: int,
     *     inside_count: int,
     *     outside_count: int,
     *     not_arrived_count: int,
     *     reentry_count: int,
     *     envergan_inside_count: int,
     *     external_inside_count: int,
     *     attendance_percentage: float
     * }
     */
    private function calculateMetrics(Event $event): array
    {
        $confirmed = Registration::where('event_id', $event->id)->where('status', Registration::STATUS_CONFIRMED);
        $registered = (clone $confirmed)->count();
        $checkedIn = (clone $confirmed)->whereHas('ticket.admissions', fn ($query) => $query
            ->where('direction', Admission::DIRECTION_ENTRY))->count();
        $inside = (clone $confirmed)->whereHas('ticket.latestAdmission', fn ($query) => $query
            ->where('direction', Admission::DIRECTION_ENTRY))->count();
        $outside = (clone $confirmed)->whereHas('ticket.latestAdmission', fn ($query) => $query
            ->where('direction', Admission::DIRECTION_EXIT))->count();
        $notArrived = max(0, $registered - $checkedIn);
        $entryMovements = Admission::where('direction', Admission::DIRECTION_ENTRY)
            ->whereHas('ticket.registration', fn ($query) => $query
                ->where('event_id', $event->id)->where('status', Registration::STATUS_CONFIRMED))->count();
        $reentries = max(0, $entryMovements - $checkedIn);
        $enverganInside = (clone $confirmed)->where('attendee_type', 'student')
            ->whereHas('ticket.latestAdmission', fn ($query) => $query->where('direction', Admission::DIRECTION_ENTRY))->count();
        $externalInside = (clone $confirmed)->whereIn('attendee_type', ['guest', 'alumni'])
            ->whereHas('ticket.latestAdmission', fn ($query) => $query->where('direction', Admission::DIRECTION_ENTRY))->count();
        $percentage = $registered > 0 ? round(($checkedIn / $registered) * 100, 1) : 0.0;

        return [
            'total_capacity' => $event->capacity,
            'registered_count' => $registered,
            'checked_in_count' => $checkedIn,
            'inside_count' => $inside,
            'outside_count' => $outside,
            'not_arrived_count' => $notArrived,
            'reentry_count' => $reentries,
            'envergan_inside_count' => $enverganInside,
            'external_inside_count' => $externalInside,
            'attendance_percentage' => $percentage,
        ];
    }

    /**
     * Compute breakdown of admissions per gate.
     *
     * @return array<int, array{id: int, name: string, code: string, count: int}>
     */
    private function calculateGateStats(Event $event): array
    {
        return $event->accessPoints->map(function ($ap) use ($event) {
            $count = Admission::where('access_point_id', $ap->id)
                ->whereHas('ticket.registration', fn ($q) => $q
                    ->where('event_id', $event->id)
                    ->where('status', Registration::STATUS_CONFIRMED))
                ->count();

            return [
                'id' => $ap->id,
                'name' => $ap->name,
                'code' => $ap->code,
                'count' => $count,
            ];
        })->toArray();
    }

    /**
     * Get recent admissions stream for the event.
     *
     * Scoped to still-confirmed registrations so the operational feed,
     * the gate breakdown, and the "checked in" KPI describe active attendees.
     */
    private function getRecentAdmissions(Event $event)
    {
        return Admission::with(['ticket.registration.user', 'accessPoint', 'scannedBy'])
            ->whereHas('ticket.registration', fn ($q) => $q
                ->where('event_id', $event->id)
                ->where('status', Registration::STATUS_CONFIRMED))
            ->orderBy('id', 'desc')
            ->take(10)
            ->get();
    }
}
