@extends('layouts.app')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">All Events</h1>
        <p style="color: var(--neutral-muted); font-size: 0.875rem;">Manage event lifecycle, capacities, and gate access</p>
    </div>
    <div>
        <a href="{{ route('organizer.events.create') }}" class="btn-primary">
            <span>+</span> Create Event
        </a>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container" style="border: none;">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Venue</th>
                    <th>Registration Window (PHT)</th>
                    <th>Event Date (PHT)</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>
                            <a href="{{ route('organizer.events.show', $event) }}" style="font-weight: 600; color: var(--mseuf-maroon); text-decoration: none;">
                                {{ $event->title }}
                            </a>
                        </td>
                        <td>{{ $event->venue->name }}</td>
                        <td style="font-size: 0.8rem;">
                            {{ \App\Support\EventSchedule::local($event->registration_opens_at)->format('M d') }} - {{ \App\Support\EventSchedule::local($event->registration_closes_at)->format('M d, Y') }}
                        </td>
                        <td style="font-size: 0.8rem;">
                            {{ \App\Support\EventSchedule::local($event->starts_at)->format('M d, Y h:i A') }} PHT
                        </td>
                        <td class="mono">{{ $event->capacity }}</td>
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
                            <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">Overview</a>
                            <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--neutral-muted);">
                            No events found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($events->hasPages())
        <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--neutral-border);">
            {{ $events->links() }}
        </div>
    @endif
</div>
@endsection
