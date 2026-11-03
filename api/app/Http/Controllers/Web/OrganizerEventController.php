<?php

namespace App\Http\Controllers\Web;

use App\Actions\ConfirmImportReceipt;
use App\Actions\ConfirmSnapshotReceipt;
use App\Actions\ExportSnapshot;
use App\Actions\PrepareEventSnapshot;
use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
        $receiptNodeId = null;
        $importReceiptNodeId = null;
        if (config('ingress.mode') === 'cloud' && $event->snapshot_prepared_at !== null && Auth::user()->can('update', $event)) {
            $receiptNodeId = DB::table('event_snapshots')
                ->join('event_snapshot_receipts', 'event_snapshots.id', '=', 'event_snapshot_receipts.snapshot_id')
                ->join('event_nodes', 'event_nodes.id', '=', 'event_snapshot_receipts.node_id')
                ->whereColumn('event_snapshot_receipts.node_id', 'event_snapshots.target_node_id')
                ->whereColumn('event_snapshot_receipts.sha256', 'event_snapshots.sha256')
                ->whereNull('event_nodes.revoked_at')->whereNotNull('event_nodes.key_ciphertext')
                ->where('event_snapshots.event_id', $event->id)->where('event_snapshots.version', $event->snapshot_version)
                ->whereNull('event_snapshots.invalidated_at')->value('event_snapshot_receipts.node_id');
        }

        if ($receiptNodeId !== null) {
            $importReceiptNodeId = DB::table('event_snapshots')
                ->join('event_snapshot_import_receipts', 'event_snapshots.id', '=', 'event_snapshot_import_receipts.snapshot_id')
                ->whereColumn('event_snapshot_import_receipts.node_id', 'event_snapshots.target_node_id')
                ->whereColumn('event_snapshot_import_receipts.sha256', 'event_snapshots.sha256')
                ->where('event_snapshots.event_id', $event->id)->where('event_snapshots.version', $event->snapshot_version)
                ->whereNull('event_snapshots.invalidated_at')->value('event_snapshot_import_receipts.node_id');
        }

        return view('organizer.events.show', compact('event', 'staffUsers', 'receiptNodeId', 'importReceiptNodeId'));
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

        // Use the registration lock boundary so capacity cannot drop below a concurrent signup.
        DB::transaction(function () use ($event, $validated) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();

            $confirmed = Registration::where('event_id', $lockedEvent->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->count();

            if ($validated['capacity'] < $confirmed) {
                throw ValidationException::withMessages([
                    'capacity' => "Capacity cannot be lowered below the {$confirmed} confirmed registrations for this event.",
                ]);
            }

            $lockedEvent->update($validated);
        });

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Event updated successfully.');
    }

    /**
     * Publish draft event.
     */
    public function publish(Event $event): RedirectResponse
    {
        Gate::authorize('publish', $event);

        DB::transaction(function () use ($event) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();
            $lockedEvent->update(['status' => Event::STATUS_PUBLISHED]);
        });

        return redirect()->route('organizer.events.show', $event)
            ->with('success', "Event '{$event->title}' is now published and visible to attendees.");
    }

    /**
     * Close registration without transferring admission authority.
     */
    public function closeRegistration(Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        DB::transaction(function () use ($event) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedEvent->isPublished()) {
                throw ValidationException::withMessages([
                    'registration' => 'Only published events can close registration.',
                ]);
            }

            if ($lockedEvent->registration_closed_at === null) {
                $lockedEvent->assertSnapshotNotPrepared();
                $lockedEvent->update(['registration_closed_at' => now()]);
            }
        });

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Registration is closed.');
    }

    /**
     * Prepare an encrypted snapshot without handing off admission authority.
     */
    public function prepareSnapshot(Event $event, PrepareEventSnapshot $action): RedirectResponse
    {
        Gate::authorize('update', $event);
        $action->execute($event->id);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Snapshot prepared. Admission is not ready yet.');
    }

    /**
     * Download an encrypted package for the chosen node.
     */
    public function exportSnapshot(Request $request, Event $event, ExportSnapshot $action): Response
    {
        Gate::authorize('update', $event);
        abort_if(! $request->secure() && ! app()->environment(['local', 'testing']), 403, 'HTTPS is required.');
        $validated = $request->validate(['node_id' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/']]);
        $sealed = $action->execute($event->id, $validated['node_id']);

        return response($sealed, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="ingress-snapshot.bin"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Record an authenticated node receipt without enabling admission.
     */
    public function confirmSnapshotReceipt(Request $request, Event $event, ConfirmSnapshotReceipt $action): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_if(! $request->secure() && ! app()->environment(['local', 'testing']), 403, 'HTTPS is required.');
        $validated = $request->validate(['receipt' => ['required', 'string', 'max:4096']]);
        $action->execute($event->id, $validated['receipt']);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Snapshot received by the node. Admission is not ready yet.');
    }

    /**
     * Record an authenticated import receipt without enabling admission.
     */
    public function confirmImportReceipt(Request $request, Event $event, ConfirmImportReceipt $action): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_if(! $request->secure() && ! app()->environment(['local', 'testing']), 403, 'HTTPS is required.');
        $validated = $request->validate(['receipt' => ['required', 'string', 'max:4096']]);
        $action->execute($event->id, $validated['receipt']);

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Snapshot import confirmed. Admission is not ready yet.');
    }

    /**
     * Discard a provisional snapshot before any authority handoff.
     */
    public function discardSnapshot(Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        DB::transaction(function () use ($event) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedEvent->snapshot_prepared_at !== null) {
                DB::table('event_snapshots')->where('event_id', $lockedEvent->id)
                    ->where('version', $lockedEvent->snapshot_version)->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now(), 'updated_at' => now()]);
                $lockedEvent->snapshot_prepared_at = null;
                $lockedEvent->save();
            }
        });

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Snapshot discarded. Event details can be changed.');
    }

    /**
     * Cancel event.
     */
    public function cancel(Event $event): RedirectResponse
    {
        Gate::authorize('cancel', $event);

        DB::transaction(function () use ($event) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedEvent->snapshot_prepared_at !== null) {
                DB::table('event_snapshots')->where('event_id', $lockedEvent->id)
                    ->where('version', $lockedEvent->snapshot_version)->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now(), 'updated_at' => now()]);
            }
            $lockedEvent->status = Event::STATUS_CANCELLED;
            $lockedEvent->snapshot_prepared_at = null;
            $lockedEvent->save();
        });

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

        $created = DB::transaction(function () use ($event, $code, $validated) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();
            if ($lockedEvent->accessPoints()->where('code', $code)->exists()) {
                return false;
            }

            $lockedEvent->accessPoints()->create([
                'name' => $validated['name'], 'code' => $code, 'is_active' => true,
            ]);

            return true;
        });

        if (! $created) {
            return back()->with('error', "Access point with code '{$code}' already exists.");
        }

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
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', User::ROLE_STAFF)),
            ],
            'access_point_id' => [
                'nullable',
                Rule::exists('access_points', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
        ]);

        DB::transaction(function () use ($event, $validated) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();
            EventStaff::updateOrCreate(
                ['event_id' => $lockedEvent->id, 'user_id' => $validated['user_id']],
                ['access_point_id' => $validated['access_point_id'] ?? null]
            );
        });

        return back()->with('success', 'Staff member assigned successfully.');
    }
}
