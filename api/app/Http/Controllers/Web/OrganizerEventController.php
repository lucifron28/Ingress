<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrganizerEventController extends Controller
{
    /**
     * Organizer dashboard overview.
     */
    public function dashboard(): View
    {
        $user = Auth::user();
        $query = Event::with(['venue', 'accessPoints', 'staffAssignments']);

        if (! $user->isAdmin()) {
            $query->where('organizer_id', $user->id);
        }

        $events = $query->orderBy('starts_at', 'asc')->get();

        $stats = [
            'total_events' => $events->count(),
            'published_events' => $events->where('status', Event::STATUS_PUBLISHED)->count(),
            'draft_events' => $events->where('status', Event::STATUS_DRAFT)->count(),
            'total_capacity' => $events->sum('capacity'),
        ];

        return view('organizer.dashboard', compact('events', 'stats'));
    }

    /**
     * List organizer events.
     */
    public function index(): View
    {
        $user = Auth::user();
        $query = Event::with('venue');

        if (! $user->isAdmin()) {
            $query->where('organizer_id', $user->id);
        }

        $events = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('organizer.events.index', compact('events'));
    }

    /**
     * Show create event form.
     */
    public function create(): View
    {
        $venues = Venue::orderBy('name')->get();
        return view('organizer.events.create', compact('venues'));
    }

    /**
     * Store new event.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'registration_opens_at' => ['required', 'date'],
            'registration_closes_at' => ['required', 'date', 'after:registration_opens_at'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $slug = Str::slug($validated['title']).'-'.Str::random(6);

        $event = Event::create([
            'organizer_id' => Auth::id(),
            'venue_id' => $validated['venue_id'],
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'capacity' => $validated['capacity'],
            'registration_opens_at' => $validated['registration_opens_at'],
            'registration_closes_at' => $validated['registration_closes_at'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'status' => Event::STATUS_DRAFT,
        ]);

        // Automatically provision default access points for campus events
        AccessPoint::create([
            'event_id' => $event->id,
            'name' => 'Main Entrance',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        AccessPoint::create([
            'event_id' => $event->id,
            'name' => 'Gate A',
            'code' => 'GATE-A',
            'is_active' => true,
        ]);
        AccessPoint::create([
            'event_id' => $event->id,
            'name' => 'Gate B',
            'code' => 'GATE-B',
            'is_active' => true,
        ]);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', "Event '{$event->title}' created successfully in draft mode.");
    }

    /**
     * View event overview.
     */
    public function show(Event $event): View
    {
        Gate::authorize('view', $event);

        $event->load(['venue', 'accessPoints', 'staffAssignments.user', 'staffAssignments.accessPoint']);
        $staffUsers = User::where('role', User::ROLE_STAFF)->orderBy('name')->get();

        return view('organizer.events.show', compact('event', 'staffUsers'));
    }

    /**
     * Show edit event form.
     */
    public function edit(Event $event): View
    {
        Gate::authorize('update', $event);

        $venues = Venue::orderBy('name')->get();
        return view('organizer.events.edit', compact('event', 'venues'));
    }

    /**
     * Update event details.
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'registration_opens_at' => ['required', 'date'],
            'registration_closes_at' => ['required', 'date', 'after:registration_opens_at'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $event->update($validated);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Event updated successfully.');
    }

    /**
     * Publish draft event.
     */
    public function publish(Event $event): RedirectResponse
    {
        Gate::authorize('publish', $event);

        $event->update(['status' => Event::STATUS_PUBLISHED]);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', "Event '{$event->title}' is now published and visible to attendees.");
    }

    /**
     * Cancel event.
     */
    public function cancel(Event $event): RedirectResponse
    {
        Gate::authorize('cancel', $event);

        $event->update(['status' => Event::STATUS_CANCELLED]);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', "Event '{$event->title}' has been cancelled.");
    }

    /**
     * Add custom access point to event.
     */
    public function storeAccessPoint(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manageStaff', $event);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash'],
        ]);

        $code = strtoupper($validated['code']);

        if ($event->accessPoints()->where('code', $code)->exists()) {
            return back()->with('error', "Access point with code '{$code}' already exists.");
        }

        $event->accessPoints()->create([
            'name' => $validated['name'],
            'code' => $code,
            'is_active' => true,
        ]);

        return back()->with('success', "Access point '{$validated['name']}' added.");
    }

    /**
     * Assign staff to event and access point.
     */
    public function assignStaff(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manageStaff', $event);

        $validated = $request->validate([
            'user_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', User::ROLE_STAFF)),
            ],
            'access_point_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('access_points', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
        ]);

        EventStaff::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $validated['user_id']],
            ['access_point_id' => $validated['access_point_id'] ?? null]
        );

        return back()->with('success', 'Staff member assigned successfully.');
    }
}
