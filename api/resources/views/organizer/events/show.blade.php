@extends('layouts.app')

@section('content')
@if ($errors->has('event') || $errors->has('registration') || $errors->has('transfer') || $errors->has('node_id') || $errors->has('receipt'))
    <div role="alert" style="background: #fef2f2; color: #991b1b; padding: 1rem; margin-bottom: 1rem;">
        {{ $errors->first('event') ?: ($errors->first('registration') ?: ($errors->first('transfer') ?: ($errors->first('node_id') ?: $errors->first('receipt')))) }}
    </div>
@endif
<header id="event-overview" class="event-detail-header" tabindex="-1">
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
            </dl>
        </div>

        <div class="event-detail-header__actions">
            @if ($event->isDraft())
                <form method="POST" action="{{ route('organizer.events.publish', $event) }}" onsubmit="return confirm('Publish this event to make it visible to students?');">
                    @csrf
                    <button type="submit" class="btn-primary">
                        <x-icon name="check-circle-2" size="17" />
                        Publish Event
                    </button>
                </form>
            @endif

            @if (config('ingress.mode') === 'cloud' && $event->isPublished() && auth()->user()->can('update', $event))
                @if ($event->snapshot_prepared_at && ! $authority)
                    <form method="POST" action="{{ route('organizer.events.snapshot.discard', $event) }}" onsubmit="return confirm('Discard this snapshot to change event details?');">
                        @csrf
                    <button type="submit" class="btn-secondary"><x-icon name="x-circle" size="17" />Discard Snapshot</button>
                    </form>
                @elseif (! $event->snapshot_prepared_at)
                    <form method="POST" action="{{ route('organizer.events.snapshot.prepare', $event) }}">
                        @csrf
                    <button type="submit" class="btn-secondary"><x-icon name="download" size="17" />Prepare Snapshot</button>
                    </form>
                @endif
            @endif

            @if ($event->isPublished() && ! $event->registration_closed_at && ! $event->snapshot_prepared_at)
                <form method="POST" action="{{ route('organizer.events.registration.close', $event) }}" onsubmit="return confirm('Close registration? It cannot be reopened.');">
                    @csrf
                    <button type="submit" class="btn-secondary"><x-icon name="x-circle" size="17" />Close Registration</button>
                </form>
            @endif

            @if ($event->isPublished() && ! $authority)
                <form method="POST" action="{{ route('organizer.events.cancel', $event) }}" onsubmit="return confirm('Are you sure you want to cancel this event?');">
                    @csrf
                    <button type="submit" class="btn-danger">
                        <x-icon name="x-circle" size="17" />
                        Cancel Event
                    </button>
                </form>
            @endif

            @if (! $event->snapshot_prepared_at)
                <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary">
                    <x-icon name="pencil" size="17" />
                    Edit Details
                </a>
            @endif
        </div>
    </div>
</header>

<x-event-island-nav :event="$event" active="overview" :track-sections="true" />

<div class="event-overview-grid">
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
            @if ($errors->hasAny(['guest', 'guest_name', 'guest_email', 'attendee_type']))
                <p role="alert" style="color: #991b1b;">{{ $errors->first('guest') ?: ($errors->first('guest_name') ?: ($errors->first('guest_email') ?: $errors->first('attendee_type'))) }}</p>
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
            @empty
                <p>No guest or alumni passes issued.</p>
            @endforelse
        </section>
        @if ($walkInRecoveries->isNotEmpty())
            <section class="card" style="grid-column: 1 / -1;">
                <h2>Recent walk-in pass recoveries</h2>
                <p>Showing up to 50 synchronized recoveries. Records may be delayed while the node is offline.</p>
                @foreach ($walkInRecoveries as $recovery)
                    <p>{{ $recovery->recovered_at }} &middot; {{ $recovery->staff_name }} &middot; {{ $recovery->public_id }} &middot; {{ $recovery->node_id }}</p>
                @endforeach
            </section>
        @endif
    @endif
    <section class="card event-details-card" aria-labelledby="event-details-title">
        <h2 id="event-details-title" class="event-section-title">Event Schedule &amp; Configuration</h2>

        <section class="event-detail-section" aria-labelledby="event-dates-title">
            <h3 id="event-dates-title" class="event-detail-section__title">Event dates</h3>
            <div class="event-date-grid">
                <div class="event-date-block">
                    <span class="event-date-block__label">Starts</span>
                    <time class="event-date-block__datetime" datetime="{{ $event->starts_at->format('c') }}">
                        <span class="event-date-block__date">{{ $event->starts_at->format('F j, Y') }}</span>
                        <span class="event-date-block__time"><x-icon name="clock-3" size="16" />{{ $event->starts_at->format('g:i A') }}</span>
                    </time>
                </div>
                <div class="event-date-block">
                    <span class="event-date-block__label">Ends</span>
                    <time class="event-date-block__datetime" datetime="{{ $event->ends_at->format('c') }}">
                        <span class="event-date-block__date">{{ $event->ends_at->format('F j, Y') }}</span>
                        <span class="event-date-block__time"><x-icon name="clock-3" size="16" />{{ $event->ends_at->format('g:i A') }}</span>
                    </time>
                </div>
            </div>
        </section>

        <section class="event-detail-section" aria-labelledby="event-registration-title">
            <div class="event-detail-section__header">
                <h3 id="event-registration-title" class="event-detail-section__title">Registration period</h3>
                @if ($event->registration_closed_at)
                    <span class="badge badge-neutral">Closed by organizer</span>
                @endif
            </div>
            <dl class="event-registration-grid">
                <div class="event-registration-item">
                    <dt>Opens</dt>
                    <dd>
                        <time datetime="{{ $event->registration_opens_at->format('c') }}">
                            <span>{{ $event->registration_opens_at->format('F j, Y') }}</span>
                            <span class="event-registration-item__time"><x-icon name="clock-3" size="15" />{{ $event->registration_opens_at->format('g:i A') }}</span>
                        </time>
                    </dd>
                </div>
                <div class="event-registration-item">
                    <dt>Closes</dt>
                    <dd>
                        <time datetime="{{ $event->registration_closes_at->format('c') }}">
                            <span>{{ $event->registration_closes_at->format('F j, Y') }}</span>
                            <span class="event-registration-item__time"><x-icon name="clock-3" size="15" />{{ $event->registration_closes_at->format('g:i A') }}</span>
                        </time>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="event-detail-section" aria-labelledby="event-about-title">
            <h3 id="event-about-title" class="event-detail-section__title">About this Event</h3>
            <p class="event-about">{{ $event->description ?: 'No description provided.' }}</p>
        </section>

        <section class="event-detail-section event-admission" aria-labelledby="event-admission-title">
            <h3 id="event-admission-title" class="event-detail-section__title">Admission policies</h3>
            <dl class="event-admission__list">
                <div class="event-admission__item">
                    <dt>Re-entry</dt>
                    <dd><span class="badge badge-neutral">{{ $event->re_entry_allowed ? 'Allowed' : 'Not allowed' }}</span></dd>
                </div>
                <div class="event-admission__item">
                    <dt>Identity verification</dt>
                    <dd><span class="badge badge-neutral">{{ $event->identity_verification_level === 'id_check' ? 'Approved ID check' : 'Name and identifier comparison' }}</span></dd>
                </div>
            </dl>
        </section>
    </section>

    <!-- Quick Stats Card -->
    <section class="card event-capacity-summary" aria-labelledby="event-capacity-title">
        <h2 id="event-capacity-title" class="event-section-title">Capacity Summary</h2>
        <div class="event-capacity-summary__metrics">
            <div class="event-capacity-metric">
                <div class="event-capacity-metric__label">Max Capacity</div>
                <div class="event-capacity-metric__value mono">{{ number_format($event->capacity) }}</div>
            </div>
            <div class="event-capacity-metric">
                <div class="event-capacity-metric__label">Registered Attendees</div>
                <div class="event-capacity-metric__value mono">
                    {{ $event->registrations()->where('status', \App\Models\Registration::STATUS_CONFIRMED)->count() }}
                </div>
            </div>
            <div class="event-capacity-metric">
                <div class="event-capacity-metric__label">Admitted / Checked In</div>
                <div class="event-capacity-metric__value mono">
                    {{ \App\Models\Admission::forCurrentCredential()->whereHas('ticket.registration', fn($q) => $q->where('event_id', $event->id))->count() }}
                </div>
            </div>
        </div>
    </section>
</div>

<div class="event-management-grid">
    <section id="event-gates" class="card event-management-section" tabindex="-1" aria-labelledby="event-gates-title">
        <h2 id="event-gates-title" class="event-management-section__title">
            Gate Access Points
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Entrance turnstiles and gates where staff scan attendee tickets.
        </p>

        <div class="table-container event-management-table" role="region" aria-label="Gate access points" tabindex="0">
            <table>
                <caption class="visually-hidden">Configured gate access points and statuses</caption>
                <thead>
                    <tr>
                        <th scope="col">Gate Name</th>
                        <th scope="col">Code</th>
                        <th scope="col">Status</th>
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
        <form method="POST" action="{{ route('organizer.events.access-points.store', $event) }}" class="event-management-form">
            @csrf
            <h3 class="event-management-form__title">Add Custom Access Point</h3>
            <div class="event-management-form__fields event-management-form__fields--gate">
                <label class="visually-hidden" for="new-gate-name">Gate name</label>
                <input id="new-gate-name" type="text" name="name" required class="form-input" placeholder="e.g. VIP Entrance">
                <label class="visually-hidden" for="new-gate-code">Gate code</label>
                <input id="new-gate-code" type="text" name="code" required class="form-input mono" placeholder="VIP">
                <button type="submit" class="btn-primary" style="white-space: nowrap;">Add Gate</button>
            </div>
        </form>
        @endif
    </section>

    <section id="event-staff" class="card event-management-section" tabindex="-1" aria-labelledby="event-staff-title">
        <h2 id="event-staff-title" class="event-management-section__title">
            Event Staff Assignments
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Authorized staff members permitted to scan tickets for this event.
        </p>

        <div class="table-container event-management-table" role="region" aria-label="Event staff assignments" tabindex="0">
            <table>
                <caption class="visually-hidden">Event staff members and their assigned gates</caption>
                <thead>
                    <tr>
                        <th scope="col">Staff Member</th>
                        <th scope="col">Assigned Gate</th>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" style="text-align: center; color: var(--neutral-muted);">No staff assigned yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $event->snapshot_prepared_at)
        <form method="POST" action="{{ route('organizer.events.staff.assign', $event) }}" class="event-management-form">
            @csrf
            <h3 class="event-management-form__title">Assign Staff Member</h3>
            <div class="event-management-form__fields">
                <label class="visually-hidden" for="staff-user">Staff member</label>
                <select id="staff-user" name="user_id" required class="form-select">
                    <option value="">-- Select Staff User --</option>
                    @foreach ($staffUsers as $staff)
                        <option value="{{ $staff->id }}">{{ $staff->name }} ({{ $staff->email }})</option>
                    @endforeach
                </select>
                <label class="visually-hidden" for="staff-gate">Assigned gate</label>
                <select id="staff-gate" name="access_point_id" class="form-select">
                    <option value="">-- Any Gate (All Gates) --</option>
                    @foreach ($event->accessPoints as $ap)
                        <option value="{{ $ap->id }}">{{ $ap->name }} ({{ $ap->code }})</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary event-management-form__submit">Assign to Event</button>
        </form>
        @endif
    </section>
</div>

@include('organizer.events.partials.ticket-transfers')
@endsection
