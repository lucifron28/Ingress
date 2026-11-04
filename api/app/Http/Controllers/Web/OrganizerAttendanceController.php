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
        $query = Registration::with(['user', 'ticket.admissions.accessPoint'])
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
                $query->whereHas('ticket.admissions');
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
     *     not_arrived_count: int,
     *     attendance_percentage: float
     * }
     */
    private function calculateMetrics(Event $event): array
    {
        $registered = Registration::where('event_id', $event->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->count();

        // Physical admission is ticket-level: rotating credentials never resets
        // or removes an admitted attendee from attendance metrics.
        $checkedIn = Registration::where('event_id', $event->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->whereHas('ticket.admissions')
            ->count();
        $notArrived = max(0, $registered - $checkedIn);
        $percentage = $registered > 0 ? round(($checkedIn / $registered) * 100, 1) : 0.0;

        return [
            'total_capacity' => $event->capacity,
            'registered_count' => $registered,
            'checked_in_count' => $checkedIn,
            'not_arrived_count' => $notArrived,
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
            ->orderBy('admitted_at', 'desc')
            ->take(10)
            ->get();
    }
}
