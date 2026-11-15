@extends('layouts.app')

@section('content')
@if ($errors->has('event') || $errors->has('registration') || $errors->has('transfer') || $errors->has('node_id') || $errors->has('receipt'))
    <div role="alert" style="background: #fef2f2; color: #991b1b; padding: 1rem; margin-bottom: 1rem;">
        {{ $errors->first('event') ?: ($errors->first('registration') ?: ($errors->first('transfer') ?: ($errors->first('node_id') ?: $errors->first('receipt')))) }}
    </div>
@endif
<header id="event-overview" class="page-header page-header--editorial event-detail-header" tabindex="-1">
    <x-page-header-watermark />
    <div class="event-detail-header__top">
        <div class="event-detail-header__identity">
            <div class="event-detail-header__title-row">
                <h1 class="event-detail-header__title">{{ $event->title }}</h1>
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
            <dl class="event-detail-header__meta">
                <div><dt><x-icon name="map-pin" size="16" />Venue</dt><dd>{{ $event->venue->name }}</dd></div>
                <div><dt><x-icon name="users" size="16" />Capacity</dt><dd class="mono">{{ number_format($event->capacity) }}</dd></div>
                <div><dt>Re-entry</dt><dd>{{ $event->re_entry_allowed ? 'Allowed' : 'Not allowed' }}</dd></div>
                <div><dt>Identity</dt><dd>{{ $event->identity_verification_level === 'id_check' ? 'Approved ID check' : 'Standard details' }}</dd></div>
            </dl>
        </div>

        <div class="event-detail-header__actions">
            @if ($event->isDraft())
                <form id="publish-event" method="POST" action="{{ route('organizer.events.publish', $event) }}" onsubmit="return confirm('Publish this event to make it visible to students?');">
                    @csrf
                    <button type="submit" class="btn-primary"><x-icon name="check-circle-2" size="17" />Publish Event</button>
                </form>
            @endif

            @if (config('ingress.mode') === 'cloud' && $event->isPublished() && auth()->user()->can('update', $event) && $event->snapshot_prepared_at && ! $authority)
                <form method="POST" action="{{ route('organizer.events.snapshot.discard', $event) }}" onsubmit="return confirm('Discard this snapshot to change event details?');">
                    @csrf
                    <button type="submit" class="btn-secondary"><x-icon name="x-circle" size="17" />Discard Snapshot</button>
                </form>
            @endif

            @if ($event->isPublished() && ! $event->registration_closed_at && ! $event->snapshot_prepared_at)
                <form id="registration-close-control" method="POST" action="{{ route('organizer.events.registration.close', $event) }}" onsubmit="return confirm('Close registration? It cannot be reopened.');">
                    @csrf
                    <button type="submit" class="btn-secondary"><x-icon name="x-circle" size="17" />Close Registration</button>
                </form>
            @endif

            @if ($event->isPublished() && ! $authority)
                <form method="POST" action="{{ route('organizer.events.cancel', $event) }}" onsubmit="return confirm('Cancel this event? This action cannot be undone.');">
                    @csrf
                    <button type="submit" class="btn-danger"><x-icon name="x-circle" size="17" />Cancel Event</button>
                </form>
            @endif

            @if (! $event->snapshot_prepared_at)
                <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary"><x-icon name="pencil" size="17" />Edit Details</a>
            @endif
        </div>
    </div>
</header>

<x-event-island-nav :event="$event" active="overview" :track-sections="true" />

<div class="event-overview-grid">
    <section class="event-capacity-summary" aria-labelledby="event-capacity-title">
        <h2 id="event-capacity-title" class="event-section-title">Capacity Overview</h2>
        <div class="event-capacity-summary__metrics">
            <article class="event-capacity-metric">
                <div class="event-capacity-metric__value mono">{{ number_format($event->capacity) }}</div>
                <div class="event-capacity-metric__label">Max Capacity</div>
            </article>
            <article class="event-capacity-metric">
                <div class="event-capacity-metric__value mono">{{ $event->registrations()->where('status', \App\Models\Registration::STATUS_CONFIRMED)->count() }}</div>
                <div class="event-capacity-metric__label">Registered Attendees</div>
            </article>
            <article class="event-capacity-metric">
                <div class="event-capacity-metric__value mono">{{ \App\Models\Admission::forCurrentCredential()->whereHas('ticket.registration', fn ($query) => $query->where('event_id', $event->id))->count() }}</div>
                <div class="event-capacity-metric__label">Admitted / Checked In</div>
            </article>
        </div>
    </section>
</div>

<section id="overview-readiness" class="organizer-event-section event-anchor">

@if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event))
    @php
        $configurationTasks = [];
        if ($readiness['activeTicketCategories'] === 0) $configurationTasks[] = 'add or activate a ticket category';
        if ($readiness['activeGates'] === 0) $configurationTasks[] = 'activate an access point';
        if ($readiness['scannerAssignments'] === 0) $configurationTasks[] = 'assign scanner staff';
        $configurationComplete = $configurationTasks === [];
        $configureState = $configurationComplete ? 'complete' : 'current';
        $publishState = $event->isPublished() ? 'complete' : ($configurationComplete ? 'current' : 'upcoming');
        $prepareState = $authority !== null ? 'complete' : ($event->isPublished() && $configurationComplete ? 'current' : 'upcoming');
    @endphp
    <section class="card event-progress-guide" aria-labelledby="event-progress-heading">
        <p class="event-create-eyebrow">YOUR NEXT STEPS</p>
        <h2 id="event-progress-heading">Create → Configure → Publish → Prepare for Admission</h2>
        <ol class="event-progress-list">
            <li data-state="complete"><strong>1. Created</strong><small>Draft saved. Nothing is published yet.</small></li>
            <li data-state="{{ $configureState }}"><strong>2. Configure</strong><small>{{ $configurationComplete ? 'Tickets, gates, and scanner staff are set.' : 'Review ticket categories, gates, and scanner staff.' }}</small></li>
            <li data-state="{{ $publishState }}"><strong>3. Publish</strong><small>{{ $event->isPublished() ? 'Visible to attendees.' : 'Review the event, then publish it.' }}</small></li>
            <li data-state="{{ $prepareState }}"><strong>4. Prepare for Admission</strong><small>{{ $authority ? 'Authority transferred; activate the grant on the Node.' : 'Close registration, prepare the snapshot, and complete the Node handoff.' }}</small></li>
        </ol>

        @if ($event->isCancelled() || $event->status === \App\Models\Event::STATUS_COMPLETED)
            <p class="event-next-action"><strong>Next:</strong> {{ $readiness['nextAction'] }}</p>
        @elseif (! $configurationComplete)
            <p class="event-next-action">
                <strong>Next:</strong> {{ ucfirst(implode(', ', $configurationTasks)) }}.
                <a href="#tickets-registration">Tickets and registration</a> · <a href="#gates-staff">Gates and staff</a>
            </p>
        @elseif ($event->isDraft())
            <p class="event-next-action"><strong>Next:</strong> Review the schedule and ticket setup, then <a href="#publish-event">publish the event</a> when ready.</p>
        @else
            <p class="event-next-action"><strong>Next:</strong> {{ $readiness['nextAction'] }} <a href="#preparation">View the readiness checklist</a> · <a href="#node-handoff">Open Node handoff</a></p>
        @endif

        <p>Ticket categories and access points are configured here after event creation. Closing registration cannot be undone. Preparing a snapshot locks event, ticket, gate, and staff details. Transferring authority to the Node is irreversible; the Node must activate the grant before scanning.</p>
    </section>
@endif

@if (config('ingress.mode') === 'cloud')
<section id="preparation" class="card event-preparation event-anchor">
    <h2>Event preparation</h2>
    <p>Check the event, tickets, gates, and scanner roster before starting the signed Node handoff. Preparing a snapshot locks event, ticket, gate, and staff changes.</p>
    <ul class="event-readiness-grid">
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['isPublished'] ? 'badge-success' : 'badge-warning' }}">{{ $readiness['isPublished'] ? 'Ready' : 'Action needed' }}</span>
            <strong>Event publication</strong>
            <p>{{ $readiness['isPublished'] ? 'Students can view this published event when registration is available.' : 'Publish the event after reviewing the schedule and ticket setup.' }}</p>
        </li>
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['activeTicketCategories'] > 0 ? 'badge-success' : 'badge-warning' }}">{{ $readiness['activeTicketCategories'] }} active</span>
            <strong>Ticket categories</strong>
            <p>{{ $readiness['activeTicketCategories'] > 0 ? 'Review price, capacity, and eligibility before registration.' : 'Add or activate a ticket category before opening registration.' }}</p>
        </li>
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['activeGates'] > 0 ? 'badge-success' : 'badge-warning' }}">{{ $readiness['activeGates'] }} active</span>
            <strong>Gate access points</strong>
            <p>{{ $readiness['activeGates'] > 0 ? 'Review the gates staff will select while scanning.' : 'No active gates configured. Add or activate a gate before preparing a scanner roster.' }}</p>
        </li>
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['scannerAssignments'] > 0 ? 'badge-success' : 'badge-warning' }}">{{ $readiness['scannerAssignments'] }} assigned</span>
            <strong>Scanner staff</strong>
            <p>{{ $readiness['scannerAssignments'] > 0 ? 'Confirm each staff member and gate assignment.' : 'No scanner staff assigned. Assign at least one before preparing the snapshot; staff assignments lock with the snapshot.' }}</p>
        </li>
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['registrationClosed'] ? 'badge-success' : 'badge-warning' }}">{{ $readiness['registrationClosed'] ? 'Closed' : 'Not closed' }}</span>
            <strong>Registration</strong>
            @if ($readiness['registrationClosed'])
                <p>Registration has closed by organizer action or by its cutoff.</p>
            @else
                <p>Registration is not closed by organizer action or cutoff. The cutoff is {{ \App\Support\EventSchedule::local($event->registration_closes_at)->format('M j, Y g:i A') }} PHT. Close registration early if the Node handoff must start sooner.</p>
            @endif
        </li>
        <li class="event-readiness-item">
            <span class="badge {{ $readiness['snapshotPrepared'] ? 'badge-success' : 'badge-neutral' }}">{{ $readiness['snapshotPrepared'] ? 'Prepared' : 'Not prepared' }}</span>
            <strong>Node handoff</strong>
            <p>{{ $readiness['handoffNextStep'] }}</p>
        </li>
    </ul>
    <p class="event-next-action"><strong>Next step:</strong> {{ $readiness['nextAction'] }}</p>
</section>
@endif

<section class="card organizer-event-overview-details" aria-labelledby="event-schedule-heading">
    <h2 id="event-schedule-heading">Event schedule and details (Philippine Time, PHT)</h2>
    <dl class="organizer-event-schedule">
        <div>
            <dt>Registration period</dt>
            <dd>
                {{ \App\Support\EventSchedule::local($event->registration_opens_at)->format('M d, Y h:i A') }} to {{ \App\Support\EventSchedule::local($event->registration_closes_at)->format('M d, Y h:i A') }}
                @if ($event->registration_closed_at)<span>Closed by organizer</span>@endif
            </dd>
        </div>
        <div>
            <dt>Event program</dt>
            <dd>{{ \App\Support\EventSchedule::local($event->starts_at)->format('M d, Y h:i A') }} to {{ \App\Support\EventSchedule::local($event->ends_at)->format('M d, Y h:i A') }}</dd>
        </div>
        <div class="organizer-event-description">
            <dt>Description</dt>
            <dd>{{ $event->description ?: 'No description provided.' }}</dd>
        </div>
    </dl>
</section>
</section>

<section id="tickets-registration" class="organizer-event-section event-anchor">
    @if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event))
        <section id="ticket-categories" class="card event-anchor" style="grid-column: 1 / -1;">
            <h2>Ticket categories</h2>
            <p>Set each category's price, capacity, and student eligibility. Category details lock after the first registration to preserve ticket history.</p>
            <div class="notice" role="note" style="margin-bottom: 1rem;">
                Simulated checkout never charges money or collects card details.
                @if (config('ingress.simulated_payments.enabled'))
                    Deployment authorization is on. Paid checkout is {{ $event->simulated_payments_enabled ? 'enabled' : 'disabled' }} for this event.
                    @if (! $event->snapshot_prepared_at)
                        <form method="POST" action="{{ route('organizer.events.simulated-payments.update', $event) }}" style="display: inline-block; margin-left: .5rem;">
                            @csrf
                            <input type="hidden" name="enabled" value="{{ $event->simulated_payments_enabled ? '0' : '1' }}">
                            <button class="btn-secondary" type="submit">{{ $event->simulated_payments_enabled ? 'Disable' : 'Enable' }} for this event</button>
                        </form>
                    @endif
                @elseif ($event->simulated_payments_enabled)
                    Deployment configuration currently disables checkout for this event.
                    @if (! $event->snapshot_prepared_at)
                        <form method="POST" action="{{ route('organizer.events.simulated-payments.update', $event) }}" style="display: inline-block; margin-left: .5rem;">
                            @csrf
                            <input type="hidden" name="enabled" value="0">
                            <button class="btn-secondary" type="submit">Revoke event authorization</button>
                        </form>
                    @endif
                @else
                    Simulated checkout is disabled by deployment configuration.
                @endif
                @if ($event->simulatedPaymentAudits->isNotEmpty())
                    <ul class="meta" style="margin-bottom: 0;">
                        @foreach ($event->simulatedPaymentAudits as $audit)
                            <li>{{ $audit->enabled ? 'Enabled' : 'Disabled' }} by {{ $audit->actor?->name ?? 'a former account' }} · {{ $audit->created_at->format('M j, Y g:i A') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
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
                        @if ($category->registrations_count === 0 && $category->active_checkout_orders_count === 0 && ! $event->snapshot_prepared_at)
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
    @endif
    @if (auth()->user()->can('update', $event))
        @include('organizer.events.partials.ticket-transfers')
    @endif
    </section>

<!-- Multi-Gate Access Points & Staff Management -->
<section id="gates-staff" class="organizer-event-section organizer-event-gates-grid event-anchor">
    <!-- Access Points Card -->
    <div id="event-gates" class="card event-management-section" tabindex="-1">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Gate Access Points
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Entrance turnstiles and gates where staff scan attendee tickets.
        </p>
        @if ($errors->hasAny(['access_point', 'code']))
            <p role="alert" style="color: #991b1b; margin-bottom: 1rem;">
                {{ $errors->first('access_point') ?: $errors->first('code') }}
            </p>
        @endif

        <div class="table-container" style="margin-bottom: 1.25rem;">
            <table>
                <thead>
                    <tr>
                        <th>Gate details</th>
                        <th>Current status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($event->accessPoints as $ap)
                        <tr>
                            <td>
                                @if (! $event->snapshot_prepared_at)
                                    <form method="POST" action="{{ route('organizer.events.access-points.update', [$event, $ap]) }}" id="gate-{{ $ap->id }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: .5rem; align-items: end;">
                                        @csrf
                                        @method('PUT')
                                        <label for="gate-name-{{ $ap->id }}">Name
                                            <input id="gate-name-{{ $ap->id }}" class="form-input" name="name" maxlength="100" required value="{{ $ap->name }}">
                                        </label>
                                        <label for="gate-code-{{ $ap->id }}">Code
                                            <input id="gate-code-{{ $ap->id }}" class="form-input mono" name="code" maxlength="32" pattern="[A-Za-z0-9_-]+" required value="{{ $ap->code }}">
                                        </label>
                                        <label for="gate-active-{{ $ap->id }}">Status
                                            <select id="gate-active-{{ $ap->id }}" class="form-select" name="is_active">
                                                <option value="1" {{ $ap->is_active ? 'selected' : '' }}>Active</option>
                                                <option value="0" {{ ! $ap->is_active ? 'selected' : '' }}>Inactive</option>
                                            </select>
                                        </label>
                                        <button class="btn-secondary" type="submit">Save gate</button>
                                    </form>
                                @else
                                    <strong>{{ $ap->name }}</strong> <span class="mono">{{ $ap->code }}</span>
                                @endif
                            </td>
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
    <div id="event-staff" class="card event-management-section" tabindex="-1">
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
                    @foreach ($event->accessPoints->where('is_active', true) as $ap)
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

    <section class="card organizer-event-audit">
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
</section>

@if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event))
<section id="node-handoff" class="card event-handoff event-anchor organizer-event-handoff" aria-labelledby="node-handoff-heading">
    <h2 id="node-handoff-heading">Node Handoff</h2>
    <p>{{ $readiness['handoffNextStep'] }}</p>
    @if (! $event->snapshot_prepared_at)
        <p>Preparing a snapshot freezes event, ticket, gate, and staff changes. The Node must import it and return receipts before authority can be transferred.</p>
        <form method="POST" action="{{ route('organizer.events.snapshot.prepare', $event) }}">
            @csrf
            <button type="submit" class="btn-primary" @disabled($readiness['snapshotPreparationBlockReason'] !== null)>Prepare Snapshot</button>
        </form>
        @if ($readiness['snapshotPreparationBlockReason'])
            <p class="event-next-action" role="status"><strong>Snapshot preparation unavailable:</strong> {{ $readiness['snapshotPreparationBlockReason'] }}</p>
        @endif
    @else
        <p>Snapshot prepared. Event changes are locked. Node admission requires an activated authority grant.</p>
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
            @if ($authority)
                <form method="POST" action="{{ route('organizer.events.authority.transfer', $event) }}" onsubmit="return confirm('Download the existing authority grant again? Authority remains with this node.');">
                    @csrf
                    <button type="submit" class="btn-secondary">Download Authority Grant Again</button>
                </form>
            @else
                <form method="POST" action="{{ route('organizer.events.authority.transfer', $event) }}" onsubmit="return confirm('This irreversibly locks cloud cancellation and snapshot changes. Recovery is not available. Activate this grant on the node before scanning. Continue?');">
                    @csrf
                    <button type="submit" class="btn-danger">Transfer Authority (irreversible)</button>
                </form>
            @endif
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
    @endif
</section>
@endif

<section id="attendance" class="organizer-event-section event-anchor">
    <section class="card organizer-event-attendance" aria-labelledby="attendance-summary-heading">
        <div class="organizer-event-attendance-header">
            <div>
                <h2 id="attendance-summary-heading">Attendance</h2>
                <p>Admission totals may be delayed while the Event Node is offline. Open the attendance view for ticket and scan records.</p>
            </div>
            <a href="{{ route('organizer.attendance', $event) }}" class="btn-primary">Open attendance view</a>
        </div>
        <dl class="organizer-event-metrics">
            <div>
                <dt>Event capacity</dt>
                <dd class="mono">{{ $event->capacity }}</dd>
            </div>
            <div>
                <dt>Registered attendees</dt>
                <dd class="mono">{{ $event->registrations()->where('status', \App\Models\Registration::STATUS_CONFIRMED)->count() }}</dd>
            </div>
            <div>
                <dt>Admitted / checked in</dt>
                <dd class="mono">{{ \App\Models\Admission::forCurrentCredential()->whereHas('ticket.registration', fn ($query) => $query->where('event_id', $event->id))->count() }}</dd>
            </div>
        </dl>
    </section>
    @if (config('ingress.mode') === 'cloud' && auth()->user()->can('update', $event) && $walkInRecoveries->isNotEmpty())
        <section class="card" aria-labelledby="walk-in-recoveries-heading">
            <h2 id="walk-in-recoveries-heading">Recent walk-in pass recoveries</h2>
            <p>Showing up to 50 synchronized recoveries. Records may be delayed while the node is offline.</p>
            @foreach ($walkInRecoveries as $recovery)
                <p>{{ $recovery->recovered_at }} &middot; {{ $recovery->staff_name }} &middot; {{ $recovery->public_id }} &middot; {{ $recovery->node_id }} &middot; {{ $recovery->identity_label }} &middot; {{ $recovery->reason_label }}</p>
            @endforeach
        </section>
    @endif
</section>
@endsection
