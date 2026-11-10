@extends('layouts.app')

@section('content')
<x-page-header title="Organizer Dashboard" subtitle="Manage your campus events">
    <x-slot:actions>
        <x-ui.button :href="route('organizer.events.create')">
            <x-icon name="plus" size="18" />
            <span>Create New Event</span>
        </x-ui.button>
    </x-slot:actions>
</x-page-header>

<section class="dashboard-stats-section" aria-labelledby="dashboard-stats-title">
    <h2 id="dashboard-stats-title" class="visually-hidden">Event statistics</h2>
    <dl class="dashboard-stats-grid">
        <x-stat-card icon="calendar-days" label="Total Events" :value="$stats['total_events']" />
        <x-stat-card icon="check-circle-2" label="Published Events" :value="$stats['published_events']" />
        <x-stat-card icon="file-pen-line" label="Draft Events" :value="$stats['draft_events']" />
        <x-stat-card icon="users" label="Total Capacity" :value="number_format($stats['total_capacity'])" />
    </dl>
</section>

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
