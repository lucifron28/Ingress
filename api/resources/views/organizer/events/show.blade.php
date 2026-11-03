@extends('layouts.app')

@section('content')
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
            <p style="color: var(--neutral-muted); font-size: 0.875rem;">Venue: <strong>{{ $event->venue->name }}</strong> &bull; Capacity: <strong class="mono">{{ $event->capacity }}</strong></p>
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

            @if ($event->isPublished() && ! $event->registration_closed_at)
                <form method="POST" action="{{ route('organizer.events.registration.close', $event) }}" onsubmit="return confirm('Close registration? It cannot be reopened.');">
                    @csrf
                    <button type="submit" class="btn-secondary">Close Registration</button>
                </form>
            @endif

            @if ($event->isPublished())
                <form method="POST" action="{{ route('organizer.events.cancel', $event) }}" onsubmit="return confirm('Are you sure you want to cancel this event?');">
                    @csrf
                    <button type="submit" class="btn-danger">
                        Cancel Event
                    </button>
                </form>
            @endif

            <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary">
                Edit Details
            </a>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
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

        <form method="POST" action="{{ route('organizer.events.access-points.store', $event) }}" style="background: #f8fafc; padding: 1rem; border-radius: 4px; border: 1px solid var(--neutral-border);">
            @csrf
            <div style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--neutral-dark);">Add Custom Access Point</div>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" name="name" required class="form-input" placeholder="e.g. VIP Entrance" style="flex: 2;">
                <input type="text" name="code" required class="form-input mono" placeholder="VIP" style="flex: 1;">
                <button type="submit" class="btn-primary" style="white-space: nowrap;">Add Gate</button>
            </div>
        </form>
    </div>

    <!-- Event Staff Assignment Card -->
    <div class="card">
        <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Event Staff Assignments
        </h2>
        <p style="font-size: 0.8rem; color: var(--neutral-muted); margin-bottom: 1rem;">
            Authorized staff members permitted to scan tickets for this event.
        </p>

        <div class="table-container" style="margin-bottom: 1.25rem;">
            <table>
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Assigned Gate</th>
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

        <form method="POST" action="{{ route('organizer.events.staff.assign', $event) }}" style="background: #f8fafc; padding: 1rem; border-radius: 4px; border: 1px solid var(--neutral-border);">
            @csrf
            <div style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--neutral-dark);">Assign Staff Member</div>
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                <select name="user_id" required class="form-select" style="flex: 1;">
                    <option value="">-- Select Staff User --</option>
                    @foreach ($staffUsers as $staff)
                        <option value="{{ $staff->id }}">{{ $staff->name }} ({{ $staff->email }})</option>
                    @endforeach
                </select>
                <select name="access_point_id" class="form-select" style="flex: 1;">
                    <option value="">-- Any Gate (All Gates) --</option>
                    @foreach ($event->accessPoints as $ap)
                        <option value="{{ $ap->id }}">{{ $ap->name }} ({{ $ap->code }})</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%; justify-content: center;">Assign to Event</button>
        </form>
    </div>
</div>
@endsection
