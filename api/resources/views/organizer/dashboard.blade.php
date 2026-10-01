@extends('layouts.app')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">Organizer Dashboard</h1>
        <p style="color: var(--neutral-muted); font-size: 0.875rem;">MSEUF Event Ticketing &amp; Admission Administration</p>
    </div>
    <div>
        <a href="{{ route('organizer.events.create') }}" class="btn-primary">
            <span>+</span> Create New Event
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--neutral-muted); margin-bottom: 0.25rem;">Total Events</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--neutral-dark);">{{ $stats['total_events'] }}</div>
    </div>
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-success); margin-bottom: 0.25rem;">Published Events</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--state-success);">{{ $stats['published_events'] }}</div>
    </div>
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-warning); margin-bottom: 0.25rem;">Draft Events</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--state-warning);">{{ $stats['draft_events'] }}</div>
    </div>
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--mseuf-maroon); margin-bottom: 0.25rem;">Total Capacity</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--mseuf-maroon);">{{ number_format($stats['total_capacity']) }}</div>
    </div>
</div>

<!-- Active Events Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--neutral-border); display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1rem; font-weight: 600; color: var(--neutral-dark);">Managed Campus Events</h2>
        <span style="font-size: 0.75rem; color: var(--neutral-muted);">Showing {{ $events->count() }} events</span>
    </div>
    
    @if ($events->isEmpty())
        <div style="padding: 3rem; text-align: center; color: var(--neutral-muted);">
            <p style="margin-bottom: 1rem;">No events created yet.</p>
            <a href="{{ route('organizer.events.create') }}" class="btn-primary">Create Your First Event</a>
        </div>
    @else
        <div class="table-container" style="border: none;">
            <table>
                <thead>
                    <tr>
                        <th>Event Title</th>
                        <th>Venue</th>
                        <th>Schedule</th>
                        <th>Capacity</th>
                        <th>Gates</th>
                        <th>Staff</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <td>
                                <a href="{{ route('organizer.events.show', $event) }}" style="font-weight: 600; color: var(--mseuf-maroon); text-decoration: none;">
                                    {{ $event->title }}
                                </a>
                            </td>
                            <td>{{ $event->venue->name }}</td>
                            <td>
                                <div style="font-size: 0.8rem; font-weight: 500;">{{ $event->starts_at->format('M d, Y h:i A') }}</div>
                                <div style="font-size: 0.75rem; color: var(--neutral-muted);">to {{ $event->ends_at->format('h:i A') }}</div>
                            </td>
                            <td class="mono">{{ $event->capacity }}</td>
                            <td class="mono">{{ $event->accessPoints->count() }}</td>
                            <td class="mono">{{ $event->staffAssignments->count() }}</td>
                            <td>
                                @if ($event->isPublished())
                                    <span class="badge badge-success">Published</span>
                                @elseif ($event->isDraft())
                                    <span class="badge badge-warning">Draft</span>
                                @elseif ($event->isCancelled())
                                    <span class="badge badge-error">Cancelled</span>
                                @else
                                    <span class="badge badge-neutral">{{ ucfirst($event->status) }}</span>
                                @endif
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">Manage</a>
                                <a href="{{ route('organizer.attendance', $event) }}" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; border-color: var(--mseuf-maroon); color: var(--mseuf-maroon);">Attendance</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
