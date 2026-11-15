<?php

namespace App\Http\Controllers\Web;

use App\Actions\ConfirmImportReceipt;
use App\Actions\ConfirmSnapshotReceipt;
use App\Actions\ExpireCheckoutReservations;
use App\Actions\ExportSnapshot;
use App\Actions\PrepareEventSnapshot;
use App\Actions\TransferEventAuthority;
use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventResponsibilityAudit;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Auth\StudentEmailEligibility;
use App\Services\Checkout\CheckoutCapacity;
use App\Support\EventSchedule;
use Illuminate\Database\UniqueConstraintViolationException;
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
            $query->where(function ($query) use ($user) {
                $query->where('organizer_id', $user->id)
                    ->orWhereHas('managerAssignments', fn ($assignments) => $assignments->where('user_id', $user->id));
            });
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
            $query->where(function ($query) use ($user) {
                $query->where('organizer_id', $user->id)
                    ->orWhereHas('managerAssignments', fn ($assignments) => $assignments->where('user_id', $user->id));
            });
        }

        $events = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('organizer.events.index', compact('events'));
    }

    /**
     * Show create event form.
     */
    public function create(StudentEmailEligibility $studentEmailEligibility): View
    {
        Gate::authorize('create', Event::class);

        $venues = Venue::orderBy('name')->get();
        $requiresInstitutionalEmail = $studentEmailEligibility->requiresInstitutionalEmail();
        $institutionalDomain = $studentEmailEligibility->domain();

        return view('organizer.events.create', compact('venues', 'requiresInstitutionalEmail', 'institutionalDomain'));
    }

    /**
     * Store new event.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'registration_eligibility' => ['sometimes', 'in:public,student'],
            'registration_opens_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'registration_closes_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            're_entry_allowed' => ['sometimes', 'boolean'],
            'identity_verification_level' => ['sometimes', 'in:standard,id_check'],
            'ticket_transfers_allowed' => ['sometimes', 'boolean'],
        ]);
        $validated = array_replace($validated, EventSchedule::utcDatabaseValues($validated));

        $slug = Str::slug($validated['title']).'-'.Str::random(6);

        $event = Event::create([
            'organizer_id' => Auth::id(),
            'venue_id' => $validated['venue_id'],
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'capacity' => $validated['capacity'],
            'registration_eligibility' => $validated['registration_eligibility'] ?? Event::ELIGIBILITY_PUBLIC,
            'registration_opens_at' => $validated['registration_opens_at'],
            'registration_closes_at' => $validated['registration_closes_at'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            're_entry_allowed' => (bool) ($validated['re_entry_allowed'] ?? false),
            'identity_verification_level' => $validated['identity_verification_level'] ?? Event::IDENTITY_STANDARD,
            'ticket_transfers_allowed' => (bool) ($validated['ticket_transfers_allowed'] ?? false),
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
        Gate::authorize('update', $event);

        $event->load([
            'venue',
            'accessPoints',
            'staffAssignments.user',
            'staffAssignments.accessPoint',
            'managerAssignments.user',
            'ticketCategories' => fn ($query) => $query->withCount([
                'registrations',
                'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
                'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
            ]),
            'responsibilityAudits' => fn ($query) => $query->latest('created_at')->limit(100),
            'simulatedPaymentAudits' => fn ($query) => $query->with('actor')->latest('created_at')->limit(20),
        ]);
        $guestRegistrations = config('ingress.mode') === 'cloud' && Auth::user()->can('update', $event)
            ? $event->registrations()->whereIn('attendee_type', ['guest', 'alumni'])
                ->with(['ticket' => fn ($tickets) => $tickets->withExists('admissions')])->orderBy('id')->get() : collect();
        $ticketTransfers = Auth::user()->can('update', $event)
            ? TicketTransfer::where('event_id', $event->id)->orderByDesc('requested_at')->paginate(100) : collect();
        $walkInRecoveries = config('ingress.mode') === 'cloud' && Auth::user()->can('update', $event)
            ? DB::table('cloud_walk_in_recoveries as recovery')
                ->join('cloud_walk_in_receipts as issuance', fn ($join) => $join->on('issuance.node_id', '=', 'recovery.node_id')
                    ->on('issuance.issuance_id', '=', 'recovery.issuance_id'))
                ->join('tickets as ticket', 'ticket.id', '=', 'issuance.ticket_id')
                ->join('users as staff', 'staff.id', '=', 'recovery.recovered_by_user_id')
                ->where('recovery.event_id', $event->id)->orderByDesc('recovery.recovered_at')->limit(50)
                ->get(['recovery.recovered_at', 'recovery.node_id', 'recovery.identity_verified', 'recovery.reason',
                    'staff.name as staff_name', 'ticket.public_id'])
                ->map(function ($recovery) {
                    $recovery->identity_label = $recovery->identity_verified
                        ? 'Identity verified in person' : 'Legacy record, no identity attestation';
                    $recovery->reason_label = match ($recovery->reason) {
                        'lost' => 'Lost',
                        'damaged' => 'Damaged or unreadable',
                        'not_received' => 'Pass not received',
                        default => 'Legacy reason not recorded',
                    };

                    return $recovery;
                }) : collect();
        $receiptNodeId = null;
        $importReceiptNodeId = null;
        $authority = DB::table('cloud_event_authorities')->where('event_id', $event->id)->first();
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

        $snapshotPreparationBlockReason = null;
        if (config('ingress.mode') === 'cloud') {
            if (! $event->isPublished()) {
                $snapshotPreparationBlockReason = 'Only published events can be prepared.';
            } elseif ($event->snapshot_prepared_at === null) {
                $hasAdmissions = Admission::whereHas('ticket.registration', fn ($query) => $query->where('event_id', $event->id))->exists();
                if ($hasAdmissions) {
                    $snapshotPreparationBlockReason = 'Events with existing admissions cannot be prepared until admission history can be transferred.';
                } elseif ($event->ends_at->isPast()) {
                    $snapshotPreparationBlockReason = 'Ended events cannot be prepared.';
                } elseif ($event->registration_closed_at === null
                    && now()->lte($event->registration_closes_at) && now()->lte($event->ends_at)) {
                    $snapshotPreparationBlockReason = 'Close registration before preparing a snapshot.';
                }
            }
        }

        $readiness = [
            'isPublished' => $event->isPublished(),
            'activeTicketCategories' => $event->ticketCategories->where('is_active', true)->count(),
            'activeGates' => $event->accessPoints->where('is_active', true)->count(),
            'scannerAssignments' => $event->staffAssignments->count(),
            'registrationClosed' => $event->registration_closed_at !== null || now()->gt($event->registration_closes_at),
            'snapshotPrepared' => $event->snapshot_prepared_at !== null,
            'snapshotPreparationBlockReason' => $snapshotPreparationBlockReason,
        ];

        $readiness['handoffNextStep'] = match (true) {
            ! $readiness['snapshotPrepared'] => $snapshotPreparationBlockReason !== null
                ? 'Snapshot preparation unavailable: '.$snapshotPreparationBlockReason
                : 'Prepare a snapshot after registration closes. The package includes the current event, ticket, gate, and staff details.',
            $authority !== null => 'Activate the signed authority grant on the Event Node. Cloud cannot verify that activation.',
            $receiptNodeId === null => 'Enter the target Node ID, download the package, and import it on the Event Node.',
            $importReceiptNodeId === null => 'Confirm the import receipt after the Event Node finishes importing the snapshot.',
            default => 'Transfer authority after confirming the Event Node import, then activate the signed grant on the node.',
        };

        $readiness['nextAction'] = match (true) {
            $event->isDraft() => 'Publish the event after reviewing its schedule and ticket setup.',
            $event->isCancelled() => 'This event is cancelled. Create a new event if it should run.',
            $event->status === Event::STATUS_COMPLETED => 'This event is complete and cannot be prepared for a new Event Node admission.',
            ! $event->isPublished() => 'Only published events can be prepared for an Event Node handoff.',
            $snapshotPreparationBlockReason !== null => $snapshotPreparationBlockReason,
            $readiness['activeTicketCategories'] === 0 => 'Add or activate a ticket category before opening registration.',
            $readiness['activeGates'] === 0 => 'Add or activate a gate before preparing the snapshot.',
            $readiness['scannerAssignments'] === 0 => 'Assign scanner access before preparing the snapshot; the snapshot locks staff assignments.',
            ! $readiness['snapshotPrepared'] => 'Prepare the snapshot when registration is closed. This locks event, ticket, gate, and staff changes.',
            $authority !== null => 'Activate the signed authority grant on the Event Node. Cloud cannot verify that activation.',
            $receiptNodeId === null => 'Enter the target Node ID, download the package, and import it on the Event Node.',
            $importReceiptNodeId === null => 'Confirm the import receipt after the Event Node finishes importing the snapshot.',
            default => 'Transfer authority after confirming the Event Node import, then activate the signed grant on the node.',
        };

        return view('organizer.events.show', compact(
            'event',
            'receiptNodeId',
            'importReceiptNodeId',
            'authority',
            'guestRegistrations',
            'walkInRecoveries',
            'ticketTransfers',
            'readiness',
        ));
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
    public function update(
        Request $request,
        Event $event,
        CheckoutCapacity $capacity,
        ExpireCheckoutReservations $expiration,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1'],
            'registration_opens_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'registration_closes_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            're_entry_allowed' => ['sometimes', 'boolean'],
            'identity_verification_level' => ['sometimes', 'in:standard,id_check'],
            'ticket_transfers_allowed' => ['sometimes', 'boolean'],
        ]);
        $validated = array_replace($validated, EventSchedule::utcDatabaseValues($validated));

        // Use the registration lock boundary so capacity cannot drop below a concurrent signup.
        DB::transaction(function () use ($event, $validated, $capacity, $expiration) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();

            $expiration->forLockedEvent($lockedEvent);
            $occupied = $capacity->eventOccupancy($lockedEvent);

            if ($validated['capacity'] < $occupied) {
                throw ValidationException::withMessages([
                    'capacity' => "Capacity cannot be lowered below the {$occupied} confirmed registrations and active checkout reservations for this event.",
                ]);
            }

            $wasTransferEnabled = $lockedEvent->ticket_transfers_allowed;
            $lockedEvent->update($validated);
            if ($wasTransferEnabled && ! $lockedEvent->ticket_transfers_allowed) {
                TicketTransfer::where('event_id', $lockedEvent->id)
                    ->where('status', TicketTransfer::STATUS_PENDING)
                    ->update([
                        'status' => TicketTransfer::STATUS_EXPIRED,
                        'pending_ticket_id' => null,
                        'expired_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
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
            ->with('success', 'Snapshot prepared. Activate node authority before admission.');
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
            ->with('success', 'Snapshot received by the node.');
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
            ->with('success', 'Snapshot import confirmed.');
    }

    /**
     * Commit the authority transfer and download its signed grant.
     */
    public function transferAuthority(Request $request, Event $event, TransferEventAuthority $action): Response
    {
        Gate::authorize('update', $event);
        abort_if(! $request->secure() && ! app()->environment(['local', 'testing']), 403, 'HTTPS is required.');
        $grant = $action->execute($event->id);

        return response($grant, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="ingress-authority-grant.txt"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Discard a provisional snapshot before any authority handoff.
     */
    public function discardSnapshot(Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);

        DB::transaction(function () use ($event) {
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if (DB::table('cloud_event_authorities')->where('event_id', $lockedEvent->id)->exists()) {
                throw ValidationException::withMessages(['event' => 'Authority has left the cloud. This event cannot be changed here.']);
            }
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
            if (DB::table('cloud_event_authorities')->where('event_id', $lockedEvent->id)->exists()) {
                throw ValidationException::withMessages(['event' => 'Authority has left the cloud. This event cannot be changed here.']);
            }
            if ($lockedEvent->snapshot_prepared_at !== null) {
                DB::table('event_snapshots')->where('event_id', $lockedEvent->id)
                    ->where('version', $lockedEvent->snapshot_version)->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now(), 'updated_at' => now()]);
            }
            $lockedEvent->status = Event::STATUS_CANCELLED;
            $lockedEvent->snapshot_prepared_at = null;
            $lockedEvent->save();
            TicketTransfer::where('event_id', $lockedEvent->id)
                ->where('status', TicketTransfer::STATUS_PENDING)
                ->update([
                    'status' => TicketTransfer::STATUS_EXPIRED,
                    'pending_ticket_id' => null,
                    'expired_at' => now(),
                    'updated_at' => now(),
                ]);
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

    public function updateAccessPoint(Request $request, Event $event, AccessPoint $accessPoint): RedirectResponse
    {
        Gate::authorize('manageStaff', $event);
        $event->accessPoints()->whereKey($accessPoint->getKey())->firstOrFail();
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('access_points', 'code')->where('event_id', $event->id)->ignore($accessPoint->id),
            ],
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            DB::transaction(function () use ($event, $accessPoint, $validated): void {
                $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                $lockedEvent->assertSnapshotNotPrepared();
                $lockedAccessPoint = $lockedEvent->accessPoints()->whereKey($accessPoint->getKey())
                    ->lockForUpdate()->firstOrFail();

                $hasAdmissions = Admission::query()->whereHas('ticket.registration', fn ($registrations) => $registrations
                    ->where('event_id', $lockedEvent->id))->exists();
                if ($hasAdmissions) {
                    throw ValidationException::withMessages([
                        'access_point' => ['Gate details cannot change after admissions have been recorded.'],
                    ]);
                }

                $deactivatingAssignedGate = $lockedAccessPoint->is_active
                    && ! (bool) $validated['is_active']
                    && $lockedAccessPoint->staffAssignments()->exists();
                if ($deactivatingAssignedGate) {
                    throw ValidationException::withMessages([
                        'access_point' => ['Reassign or revoke scanner staff before deactivating this gate.'],
                    ]);
                }

                $duplicateCode = $lockedEvent->accessPoints()->where('id', '<>', $lockedAccessPoint->getKey())
                    ->whereRaw('UPPER(code) = ?', [$validated['code']])->exists();
                if ($duplicateCode) {
                    throw ValidationException::withMessages(['code' => ['A gate with this code already exists for the event.']]);
                }

                $lockedAccessPoint->update([
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'is_active' => (bool) $validated['is_active'],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['A gate with this code already exists for the event.']]);
        }

        return redirect()->route('organizer.events.show', $event)->with('success', 'Gate updated.');
    }

    /**
     * Assign staff to event and access point.
     */
    public function assignStaff(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manageStaff', $event);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:staff_email'],
            'staff_email' => ['nullable', 'string', 'email:rfc', 'max:255', 'required_without:user_id'],
            'access_point_id' => [
                'nullable',
                Rule::exists('access_points', 'id')->where(fn ($query) => $query
                    ->where('event_id', $event->id)->where('is_active', true)),
            ],
        ]);

        if (isset($validated['user_id'])) {
            $staffUser = User::query()->find($validated['user_id']);
        } else {
            $matchingAccounts = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['staff_email']))])
                ->limit(2)
                ->get();
            if ($matchingAccounts->count() > 1) {
                return back()->withErrors(['staff_email' => 'This email matches more than one account. Contact the platform administrator.']);
            }

            $staffUser = $matchingAccounts->first();
        }

        if (! $staffUser || $staffUser->isAdmin()) {
            return back()->withErrors(['staff_email' => 'Choose an existing non-administrator account.']);
        }

        try {
            $updated = DB::transaction(function () use ($event, $staffUser, $validated, $request): bool {
                $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                $lockedEvent->assertSnapshotNotPrepared();
                $lockedUser = User::query()->whereKey($staffUser->getKey())->lockForUpdate()->firstOrFail();
                $accessPointId = $validated['access_point_id'] ?? null;
                if ($accessPointId !== null && ! $lockedEvent->accessPoints()->whereKey($accessPointId)
                    ->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages([
                        'access_point_id' => ['Choose an active gate from this event.'],
                    ]);
                }

                $assignment = EventStaff::query()
                    ->where('event_id', $lockedEvent->id)
                    ->where('user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($assignment && (int) $assignment->access_point_id === (int) $accessPointId) {
                    return false;
                }

                $wasAssigned = $assignment !== null;
                $oldAccessPoint = $assignment?->accessPoint;
                if ($assignment) {
                    $assignment->access_point_id = $accessPointId;
                    $assignment->save();
                } else {
                    $assignment = EventStaff::create([
                        'event_id' => $lockedEvent->id,
                        'user_id' => $lockedUser->id,
                        'access_point_id' => $accessPointId,
                    ]);
                }

                $assignment->unsetRelation('accessPoint');
                $accessPoint = $assignment->accessPoint ?? $oldAccessPoint;
                EventResponsibilityAudit::create([
                    'event_id' => $lockedEvent->id,
                    'event_title' => $lockedEvent->title,
                    'subject_user_id' => $lockedUser->id,
                    'subject_email' => $lockedUser->email,
                    'actor_user_id' => $request->user()->id,
                    'actor_email' => $request->user()->email,
                    'action' => $wasAssigned ? EventResponsibilityAudit::ACTION_SCANNER_GATE_CHANGED : EventResponsibilityAudit::ACTION_SCANNER_ASSIGNED,
                    'access_point_id' => $accessPoint?->id,
                    'access_point_code' => $accessPoint?->code,
                    'created_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $updated = false;
        }

        return back()->with(
            $updated ? 'success' : 'info',
            $updated ? 'Scanner assignment saved successfully.' : 'That account already has this scanner assignment.',
        );
    }

    public function revokeStaff(Request $request, Event $event, User $staff): RedirectResponse
    {
        Gate::authorize('manageStaff', $event);

        DB::transaction(function () use ($event, $staff, $request): void {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();
            $lockedStaff = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            $assignment = EventStaff::query()
                ->where('event_id', $lockedEvent->id)
                ->where('user_id', $lockedStaff->id)
                ->lockForUpdate()
                ->firstOrFail();
            $accessPoint = $assignment->accessPoint;

            $assignment->delete();

            EventResponsibilityAudit::create([
                'event_id' => $lockedEvent->id,
                'event_title' => $lockedEvent->title,
                'subject_user_id' => $lockedStaff->id,
                'subject_email' => $lockedStaff->email,
                'actor_user_id' => $request->user()->id,
                'actor_email' => $request->user()->email,
                'action' => EventResponsibilityAudit::ACTION_SCANNER_REVOKED,
                'access_point_id' => $accessPoint?->id,
                'access_point_code' => $accessPoint?->code,
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Scanner access has been revoked.');
    }
}
