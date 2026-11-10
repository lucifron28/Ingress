@extends('layouts.app')

@section('content')
@if ($errors->has('event') || $errors->has('registration') || $errors->has('transfer') || $errors->has('node_id') || $errors->has('receipt'))
    <div role="alert" style="background: #fef2f2; color: #991b1b; padding: 1rem; margin-bottom: 1rem;">
        {{ $errors->first('event') ?: ($errors->first('registration') ?: ($errors->first('transfer') ?: ($errors->first('node_id') ?: $errors->first('receipt')))) }}
    </div>
@endif
<div style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">{{ $event->title }}</h1>
                @if ($event->isPublished())
                    <span class="badge badge-success">Published</span>
                @elseif ($event->isDraft())
                    <span class="badge badge-warning">Draft</span>
                @elseif ($event->isCancelled())
                    <span class="badge badge-error">Cancelled</span>
                @else
                    <span class="badge badge-neutral">{{ ucfirst($event->status) }}</span>
                @endif
            </div>
            <p style="color: var(--neutral-muted); font-size: 0.875rem;">Venue: <strong>{{ $event->venue->name }}</strong> &bull; Capacity: <strong class="mono">{{ $event->capacity }}</strong> &bull; Re-entry: <strong>{{ $event->re_entry_allowed ? 'Allowed' : 'Not allowed' }}</strong> &bull; Identity: <strong>{{ $event->identity_verification_level === 'id_check' ? 'Approved ID check' : 'Standard details' }}</strong></p>
        </div>

        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="{{ route('organizer.attendance', $event) }}" class="btn-primary" style="background-color: #1e293b; border-color: #0f172a;">
                Attendance View
            </a>

            @if ($event->isDraft())
                <form method="POST" action="{{ route('organizer.events.publish', $event) }}" onsubmit="return confirm('Publish this event to make it visible to students?');">
                    @csrf
                    <button type="submit" class="btn-primary" style="background-color: var(--state-success); border-color: #15803d;">
                        Publish Event
                    </button>
                </form>
            @endif

            @if (config('ingress.mode') === 'cloud' && $event->isPublished() && auth()->user()->can('update', $event))
                @if ($event->snapshot_prepared_at && ! $authority)
                    <form method="POST" action="{{ route('organizer.events.snapshot.discard', $event) }}" onsubmit="return confirm('Discard this snapshot to change event details?');">
                        @csrf
                        <button type="submit" class="btn-secondary">Discard Snapshot</button>
                    </form>
                @elseif (! $event->snapshot_prepared_at)
                    <form method="POST" action="{{ route('organizer.events.snapshot.prepare', $event) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">Prepare Snapshot</button>
                    </form>
                @endif
            @endif

            @if ($event->isPublished() && ! $event->registration_closed_at && ! $event->snapshot_prepared_at)
                <form method="POST" action="{{ route('organizer.events.registration.close', $event) }}" onsubmit="return confirm('Close registration? It cannot be reopened.');">
                    @csrf
                    <button type="submit" class="btn-secondary">Close Registration</button>
                </form>
            @endif

            @if ($event->isPublished() && ! $authority)
                <form method="POST" action="{{ route('organizer.events.cancel', $event) }}" onsubmit="return confirm('Are you sure you want to cancel this event?');">
                    @csrf
                    <button type="submit" class="btn-danger">
                        Cancel Event
                    </button>
                </form>
            @endif

            @if (! $event->snapshot_prepared_at)
                <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary">
                    Edit Details
                </a>
            @endif
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
    @if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event))
        <section class="card" style="grid-column: 1 / -1;">
            <h2>Ticket categories</h2>
            <p>Set each category's price, capacity, and student eligibility. Paid categories stay unavailable until simulated checkout is implemented. Category details lock after the first registration to preserve ticket history.</p>
            @if ($errors->hasAny(['name', 'price_pesos', 'capacity', 'eligibility', 'category']))
                <div role="alert" style="background: #fef2f2; color: #991b1b; padding: .75rem; margin-bottom: 1rem;">
                    {{ $errors->first('name') ?: ($errors->first('price_pesos') ?: ($errors->first('capacity') ?: ($errors->first('eligibility') ?: $errors->first('category')))) }}
                </div>
            @endif
            <div class="table-container" style="margin-bottom: 1.25rem;">
                <table>
                    <thead><tr><th>Category</th><th>Eligibility</th><th>Price</th><th>Capacity</th><th>Availability</th></tr></thead>
                    <tbody>
                    @forelse ($event->ticketCategories as $category)
                        <tr>
                            <td>{{ $category->name }} @if ($category->is_default)<span class="badge badge-info">Default</span>@endif</td>
                            <td>{{ $category->eligibility === 'student' ? 'Verified students' : 'Public' }}</td>
                            <td>{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }}</td>
                            <td>{{ $category->capacity ?? 'Event capacity' }}</td>
                            <td>{{ $category->confirmed_registrations_count }} issued @if ($category->capacity !== null) · {{ $category->remainingCapacity() }} left @endif</td>
                        </tr>
                        @if ($category->registrations_count === 0 && ! $event->snapshot_prepared_at)
                            <tr><td colspan="5">
                                <form method="POST" action="{{ route('organizer.events.ticket-categories.update', [$event, $category]) }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .5rem; align-items: end;">
                                    @csrf
                                    @method('PUT')
                                    <label>Name<input class="form-input" name="name" maxlength="100" required value="{{ $category->name }}"></label>
                                    <label>Price (PHP)<input class="form-input" name="price_pesos" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" required value="{{ number_format($category->price_minor / 100, 2, '.', '') }}"></label>
                                    <label>Capacity<input class="form-input" name="capacity" type="number" min="1" max="{{ $event->capacity }}" value="{{ $category->capacity }}" placeholder="No separate limit"></label>
                                    <label>Eligibility<select class="form-select" name="eligibility"><option value="public" {{ $category->eligibility === 'public' ? 'selected' : '' }}>Public</option><option value="student" {{ $category->eligibility === 'student' ? 'selected' : '' }}>Verified students</option></select></label>
                                    <button class="btn-secondary" type="submit">Save category</button>
                                </form>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="5">No ticket categories configured.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if (! $event->snapshot_prepared_at)
                <form method="POST" action="{{ route('organizer.events.ticket-categories.store', $event) }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .75rem; align-items: end;">
                    @csrf
                    <label>New category name<input class="form-input" name="name" maxlength="100" required></label>
                    <label>Price (PHP)<input class="form-input" name="price_pesos" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" required value="0.00"></label>
                    <label>Capacity<input class="form-input" name="capacity" type="number" min="1" max="{{ $event->capacity }}" placeholder="No separate limit"></label>
                    <label>Eligibility<select class="form-select" name="eligibility"><option value="public">Public</option><option value="student">Verified students</option></select></label>
                    <button class="btn-primary" type="submit">Add category</button>
                </form>
            @endif
        </section>
    @endif
    @if ($event->snapshot_prepared_at)
        <p class="card" style="grid-column: 1 / -1;">Snapshot prepared. Event changes are locked. Node admission requires an activated authority grant.</p>
    @endif
    @if (config('ingress.mode') === 'cloud' && $event->snapshot_prepared_at && auth()->user()->can('update', $event))
        <div class="card" style="grid-column: 1 / -1;">
            <h2>Send snapshot to Event Node</h2>
            <p>Download the encrypted package and import it on the named node. Return the node's receipts here. Activate the authority grant on the node before admission.</p>
            @if ($receiptNodeId)
                <p>Received by node {{ $receiptNodeId }}.</p>
            @endif
            @if ($importReceiptNodeId)
                <p>Import confirmed by node {{ $importReceiptNodeId }}.</p>
            @endif
            @if ($authority)
                <p>Authority was transferred to node {{ $authority->node_id }}. Cloud cancellation and snapshot changes are locked. Activate the grant on the node before scanning.</p>
            @endif
            @if ($importReceiptNodeId || $authority)
                <form method="POST" action="{{ route('organizer.events.authority.transfer', $event) }}" onsubmit="return confirm('This locks cloud cancellation and snapshot changes. Recovery is not available. Activate this grant on the node before scanning. Continue?');">
                    @csrf
                    <button type="submit" class="btn-secondary">{{ $authority ? 'Download Authority Grant Again' : 'Transfer Authority and Download Grant' }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('organizer.events.snapshot.export', $event) }}">
                @csrf
                <label for="node-id">Node ID</label>
                <input id="node-id" name="node_id" required maxlength="64" class="form-input">
                <button type="submit" class="btn-secondary">Download Package</button>
            </form>
            <form method="POST" action="{{ route('organizer.events.snapshot.receipt', $event) }}" style="margin-top: 1rem;">
                @csrf
                <label for="snapshot-receipt">Node receipt</label>
                <textarea id="snapshot-receipt" name="receipt" required maxlength="4096" class="form-input"></textarea>
                <button type="submit" class="btn-secondary">Confirm Receipt</button>
            </form>
            <form method="POST" action="{{ route('organizer.events.snapshot.import-receipt', $event) }}" style="margin-top: 1rem;">
                @csrf
                <label for="import-receipt">Node import receipt</label>
                <textarea id="import-receipt" name="receipt" required maxlength="4096" class="form-input"></textarea>
                <button type="submit" class="btn-secondary">Confirm Import</button>
            </form>
        </div>
    @endif
    @if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event))
        <section class="card" style="grid-column: 1 / -1;">
            <h2>Guest and alumni passes</h2>
            @if ($errors->hasAny(['guest', 'guest_name', 'guest_email', 'attendee_type', 'identity_verified']))
                <p role="alert" style="color: #991b1b;">{{ $errors->first('guest') ?: ($errors->first('identity_verified') ?: ($errors->first('guest_name') ?: ($errors->first('guest_email') ?: $errors->first('attendee_type')))) }}</p>
            @endif
            @if ($event->isRegistrationOpen())
                <form method="POST" action="{{ route('organizer.events.guests.store', $event) }}">
                    @csrf
                    <label for="guest-name">Name</label>
                    <input id="guest-name" class="form-input" name="guest_name" required maxlength="255" value="{{ old('guest_name') }}">
                    <label for="guest-email">Email</label>
                    <input id="guest-email" class="form-input" name="guest_email" type="email" required maxlength="255" value="{{ old('guest_email') }}">
                    <label for="attendee-type">Attendee type</label>
                    <select id="attendee-type" class="form-input" name="attendee_type"><option value="guest">Guest</option><option value="alumni">Alumni</option></select>
                    <button type="submit" class="btn-secondary">Issue pass</button>
                </form>
            @endif
            @forelse ($guestRegistrations as $guest)
                <p>{{ $guest->attendeeName() }} &middot; {{ ucfirst($guest->attendee_type) }}
                    @if ($guest->status === 'confirmed' && $guest->ticket?->isActive() && ! $event->isCancelled())
                        <a href="{{ route('organizer.events.guest-pass', [$event, $guest]) }}">Open pass</a>
                    @endif
                </p>
                @if ($event->canTransferTickets() && $guest->status === 'confirmed' && $guest->ticket?->isActive() && ! $guest->ticket->admissions_exists)
                    <form method="POST" action="{{ route('organizer.events.guests.recover', [$event, $guest]) }}">
                        @csrf
                        <label>
                            <input type="checkbox" name="identity_verified" value="1" required>
                            I verified this attendee's identity outside Ingress and will share the replacement code privately.
                        </label>
                        <button type="submit" class="btn-secondary">Rotate QR and reissue transfer code</button>
                    </form>
                @endif
            @empty
                <p>No guest or alumni passes issued.</p>
            @endforelse
        </section>
        @if ($walkInRecoveries->isNotEmpty())
            <section class="card" style="grid-column: 1 / -1;">
                <h2>Recent walk-in pass recoveries</h2>
                <p>Showing up to 50 synchronized recoveries. Records may be delayed while the node is offline.</p>
                @foreach ($walkInRecoveries as $recovery)
                    <p>{{ $recovery->recovered_at }} &middot; {{ $recovery->staff_name }} &middot; {{ $recovery->public_id }} &middot; {{ $recovery->node_id }} &middot; {{ $recovery->identity_label }} &middot; {{ $recovery->reason_label }}</p>
                @endforeach
            </section>
        @endif
    @endif
    @if (auth()->user()->can('update', $event))
        <section class="card" style="grid-column: 1 / -1;">
            <h2>Ticket transfer history</h2>
            <p>Student transfers require the event flag, a published event before its end time, and no prepared admission snapshot. History is paginated at 100 requests.</p>
            @forelse ($ticketTransfers as $transfer)
                <p>{{ $transfer->ticket_public_id }} &middot; {{ $transfer->from_name }} ({{ $transfer->from_email }}) &rarr; {{ $transfer->recipient_name }} ({{ $transfer->recipient_email }}) &middot; {{ ucfirst($transfer->status) }} &middot; Requested {{ $transfer->requested_at->format('M d, Y h:i A') }}
                    @if ($transfer->accepted_at) &middot; Accepted {{ $transfer->accepted_at->format('M d, Y h:i A') }} @endif
                    @if ($transfer->declined_at) &middot; Declined {{ $transfer->declined_at->format('M d, Y h:i A') }} @endif
                    @if ($transfer->cancelled_at) &middot; Cancelled {{ $transfer->cancelled_at->format('M d, Y h:i A') }} @endif
                    @if ($transfer->expired_at) &middot; Expired {{ $transfer->expired_at->format('M d, Y h:i A') }} @endif
                </p>
            @empty
                <p>No ticket transfer requests.</p>
            @endforelse
            {{ $ticketTransfers->links() }}
        </section>
    @endif
    <!-- Event Details Card -->
    <div class="card">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Event Schedule &amp; Configuration
        </h2>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div>
                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-muted); font-weight: 600;">Registration Period</div>
                <div style="font-size: 0.875rem; margin-top: 0.25rem;">
                    {{ $event->registration_opens_at->format('M d, Y h:i A') }}<br>
                    to {{ $event->registration_closes_at->format('M d, Y h:i A') }}
                    @if ($event->registration_closed_at)
                        <br><strong>Closed by organizer</strong>
                    @endif
                </div>
            </div>
            <div>
                <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-muted); font-weight: 600;">Event Program Schedule</div>
                <div style="font-size: 0.875rem; margin-top: 0.25rem;">
                    {{ $event->starts_at->format('M d, Y h:i A') }}<br>
                    to {{ $event->ends_at->format('M d, Y h:i A') }}
                </div>
            </div>
        </div>

        <div>
            <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--neutral-muted); font-weight: 600;">Description</div>
            <div style="font-size: 0.875rem; margin-top: 0.25rem; color: var(--neutral-body); line-height: 1.6;">
                {{ $event->description ?: 'No description provided.' }}
            </div>
        </div>
    </div>

    <!-- Quick Stats Card -->
    <div class="card">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Capacity Summary
        </h2>
        <div style="margin-bottom: 1rem;">
            <div style="font-size: 0.8rem; color: var(--neutral-muted);">Max Capacity</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);" class="mono">{{ $event->capacity }}</div>
        </div>
        <div style="margin-bottom: 1rem;">
            <div style="font-size: 0.8rem; color: var(--neutral-muted);">Registered Attendees</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: var(--mseuf-maroon);" class="mono">
                {{ $event->registrations()->where('status', \App\Models\Registration::STATUS_CONFIRMED)->count() }}
            </div>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--neutral-muted);">Admitted / Checked In</div>
            <div style="font-size: 1.75rem; font-weight: 700; color: var(--state-success);" class="mono">
                {{ \App\Models\Admission::forCurrentCredential()->whereHas('ticket.registration', fn($q) => $q->where('event_id', $event->id))->count() }}
            </div>
        </div>
    </div>
</div>

<!-- Multi-Gate Access Points & Staff Management -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <!-- Access Points Card -->
    <div class="card">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Gate Access Points
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Entrance turnstiles and gates where staff scan attendee tickets.
        </p>

        <div class="table-container" style="margin-bottom: 1.25rem;">
            <table>
                <thead>
                    <tr>
                        <th>Gate Name</th>
                        <th>Code</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($event->accessPoints as $ap)
                        <tr>
                            <td><strong>{{ $ap->name }}</strong></td>
                            <td class="mono">{{ $ap->code }}</td>
                            <td>
                                <span class="badge {{ $ap->is_active ? 'badge-success' : 'badge-neutral' }}">
                                    {{ $ap->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: var(--neutral-muted);">No access points configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $event->snapshot_prepared_at)
        <form method="POST" action="{{ route('organizer.events.access-points.store', $event) }}" style="background: #f8fafc; padding: 1rem; border-radius: 4px; border: 1px solid var(--neutral-border);">
            @csrf
            <div style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--neutral-dark);">Add Custom Access Point</div>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" name="name" required class="form-input" placeholder="e.g. VIP Entrance" style="flex: 2;">
                <input type="text" name="code" required class="form-input mono" placeholder="VIP" style="flex: 1;">
                <button type="submit" class="btn-primary" style="white-space: nowrap;">Add Gate</button>
            </div>
        </form>
        @endif
    </div>

    <!-- Event Staff Assignment Card -->
    <div class="card">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Event Staff Assignments
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Scanner access is assigned per event and can be limited to a gate. Account category does not grant scanning access.
        </p>

        <div class="table-container" style="margin-bottom: 1.25rem;">
            <table>
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Assigned Gate</th>
                        @if (! $event->snapshot_prepared_at)
                            <th>Access</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($event->staffAssignments as $assignment)
                        <tr>
                            <td>
                                <div><strong>{{ $assignment->user->name }}</strong></div>
                                <div style="font-size: 0.75rem; color: var(--neutral-muted);">{{ $assignment->user->email }}</div>
                            </td>
                            <td>
                                @if ($assignment->accessPoint)
                                    <span class="badge badge-info">{{ $assignment->accessPoint->name }}</span>
                                @else
                                    <span class="badge badge-neutral">All Gates</span>
                                @endif
                            </td>
                            @if (! $event->snapshot_prepared_at)
                                <td>
                                    <form method="POST" action="{{ route('organizer.events.staff.revoke', [$event, $assignment->user]) }}" onsubmit="return confirm('Revoke scanner access for this event?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-danger">Revoke</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $event->snapshot_prepared_at ? 2 : 3 }}" style="text-align: center; color: var(--neutral-muted);">No scanner staff assigned yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $event->snapshot_prepared_at)
        <form method="POST" action="{{ route('organizer.events.staff.assign', $event) }}" style="background: #f8fafc; padding: 1rem; border-radius: 4px; border: 1px solid var(--neutral-border);">
            @csrf
            <div style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--neutral-dark);">Assign Scanner Access</div>
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                <input type="email" name="staff_email" required class="form-input" maxlength="255" placeholder="Account email" style="flex: 1;">
                @error('staff_email') <p role="alert">{{ $message }}</p> @enderror
                <select name="access_point_id" class="form-select" style="flex: 1;">
                    <option value="">-- Any Gate (All Gates) --</option>
                    @foreach ($event->accessPoints as $ap)
                        <option value="{{ $ap->id }}">{{ $ap->name }} ({{ $ap->code }})</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center;">Assign Scanner Access</button>
        </form>
        @endif
    </div>

    @if (auth()->user()->isAdmin() && config('ingress.mode') === 'cloud')
        <section class="card">
            <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
                Event Managers
            </h2>
            <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
                Only platform administrators can assign or revoke event managers. Assign an existing account or invite a new email address for this event only.
            </p>
            <ul>
                @forelse ($event->managerAssignments as $managerAssignment)
                    <li style="margin-bottom: .5rem;">
                        {{ $managerAssignment->user->name }} ({{ $managerAssignment->user->email }})
                        <form method="POST" action="{{ route('organizer.events.managers.revoke', [$event, $managerAssignment->user]) }}" style="display: inline;" onsubmit="return confirm('Revoke manager access to this event?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger">Revoke</button>
                        </form>
                    </li>
                @empty
                    <li>No event managers assigned.</li>
                @endforelse
            </ul>
            <form method="POST" action="{{ route('organizer.events.managers.store', $event) }}" style="margin-top: 1rem;">
                @csrf
                <label for="manager-email">Manager email address</label>
                <input id="manager-email" type="email" name="email" required maxlength="255" class="form-input">
                <button type="submit" class="btn-primary">Assign event manager</button>
                @error('email') <p role="alert">{{ $message }}</p> @enderror
            </form>
        </section>
    @endif

    <section class="card" style="grid-column: 1 / -1;">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Event Responsibility Audit
        </h2>
        <ol>
            @forelse ($event->responsibilityAudits as $audit)
                <li>{{ $audit->created_at->format('M d, Y h:i A') }} · {{ str_replace('_', ' ', ucfirst($audit->action)) }} · {{ $audit->subject_email }} · by {{ $audit->actor_email }}@if ($audit->access_point_code) · {{ $audit->access_point_code }}@endif</li>
            @empty
                <li>No manager or scanner assignment changes have been recorded.</li>
            @endforelse
        </ol>
    </section>
</div>
@endsection
