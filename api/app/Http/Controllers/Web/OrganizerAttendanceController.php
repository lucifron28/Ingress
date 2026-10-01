<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizerAttendanceController extends Controller
{
    /**
     * Show live attendance view for event.
     */
    public function show(Event $event): View
    {
        Gate::authorize('view', $event);

        $event->load(['venue', 'accessPoints', 'registrations.user', 'registrations.ticket.admissions.accessPoint']);

        return view('organizer.attendance', compact('event'));
    }
}
