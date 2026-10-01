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
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('student_id', 'ilike', "%{$search}%");
            });
        }

        if ($filter = $request->input('status')) {
            $currentCredential = fn ($q) => $q->whereColumn('admissions.credential_version', 'tickets.credential_version');

            if ($filter === 'checked_in') {
                $query->whereHas('ticket.admissions', $currentCredential);
            } elseif ($filter === 'not_arrived') {
                $query->whereDoesntHave('ticket.admissions', $currentCredential);
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
            'attendee_name' => $adm->ticket?->registration?->user?->name ?? 'Attendee',
            'student_id' => $adm->ticket?->registration?->user?->student_id ?? 'N/A',
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

        // Only admissions taken with the ticket's current credential count as
        // checked in; history from a retired credential is retained for audit.
        // The registration must still be confirmed, otherwise an attendee who was
        // admitted and then cancelled would be counted as checked in while no
        // longer being counted as registered.
        $checkedIn = Admission::forCurrentCredential()
            ->whereHas('ticket.registration', fn ($q) => $q
                ->where('event_id', $event->id)
                ->where('status', Registration::STATUS_CONFIRMED))
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
            $count = Admission::forCurrentCredential()
                ->where('access_point_id', $ap->id)
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
     * Scoped to each ticket's current credential and to still-confirmed
     * registrations so the operational feed, the gate breakdown, and the
     * "checked in" KPI always describe the same set of people.
     */
    private function getRecentAdmissions(Event $event)
    {
        return Admission::with(['ticket.registration.user', 'accessPoint', 'scannedBy'])
            ->forCurrentCredential()
            ->whereHas('ticket.registration', fn ($q) => $q
                ->where('event_id', $event->id)
                ->where('status', Registration::STATUS_CONFIRMED))
            ->orderBy('admitted_at', 'desc')
            ->take(10)
            ->get();
    }
}
