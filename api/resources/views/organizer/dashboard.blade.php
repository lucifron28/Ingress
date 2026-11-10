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

<section class="dashboard-events" aria-labelledby="managed-events-title">
    <header class="dashboard-events__header">
        <div>
            <h2 id="managed-events-title" class="dashboard-events__title">Managed Campus Events</h2>
            <p class="dashboard-events__count">
                {{ $events->count() }} {{ $events->count() === 1 ? 'event' : 'events' }}
            </p>
        </div>
        <a class="dashboard-events__view-all" href="{{ route('organizer.events.index') }}">
            <span>View All</span>
            <x-icon name="arrow-up-right" size="18" />
        </a>
    </header>

    @if ($events->isEmpty())
        <x-empty-state
            title="No events created yet"
            description="Create your first campus event to start managing registrations and admission."
        >
            <x-slot:action>
                <x-ui.button :href="route('organizer.events.create')">
                    <x-icon name="plus" size="18" />
                    <span>Create Event</span>
                </x-ui.button>
            </x-slot:action>
        </x-empty-state>
    @else
        <div class="dashboard-events__table-wrap" role="region" aria-label="Managed campus events table" tabindex="0">
            <table class="dashboard-events__table">
                <caption class="visually-hidden">Campus events, schedules, capacities, operations, statuses, and actions</caption>
                <colgroup>
                    <col class="dashboard-events__column-event">
                    <col class="dashboard-events__column-schedule">
                    <col class="dashboard-events__column-capacity">
                    <col class="dashboard-events__column-operations">
                    <col class="dashboard-events__column-status">
                    <col class="dashboard-events__column-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Schedule</th>
                        <th scope="col">Capacity</th>
                        <th scope="col">Operations</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="dashboard-events__actions-heading">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <th scope="row" class="dashboard-events__event-cell">
                                <a class="dashboard-event-title" href="{{ route('organizer.events.show', $event) }}">
                                    {{ $event->title }}
                                </a>
                                <span class="dashboard-event-venue">{{ $event->venue->name }}</span>
                            </th>
                            <td><x-event-schedule :event="$event" /></td>
                            <td class="dashboard-events__capacity">
                                {{ number_format($event->capacity) }}
                            </td>
                            <td><x-event-operations :event="$event" /></td>
                            <td><x-event-status-badge :event="$event" /></td>
                            <td><x-event-actions :event="$event" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="dashboard-events__mobile-list">
            @foreach ($events as $event)
                <article class="event-card">
                    <div class="event-card__heading">
                        <div class="event-card__identity">
                            <h3 class="event-card__title">
                                <a href="{{ route('organizer.events.show', $event) }}">{{ $event->title }}</a>
                            </h3>
                            <p class="event-card__venue">{{ $event->venue->name }}</p>
                        </div>
                        <x-event-status-badge :event="$event" />
                    </div>

                    <dl class="event-card__details">
                        <div class="event-card__detail event-card__detail--schedule">
                            <dt>Schedule</dt>
                            <dd><x-event-schedule :event="$event" /></dd>
                        </div>
                        <div class="event-card__detail">
                            <dt>Capacity</dt>
                            <dd class="dashboard-events__capacity">{{ number_format($event->capacity) }}</dd>
                        </div>
                        <div class="event-card__detail">
                            <dt>Operations</dt>
                            <dd><x-event-operations :event="$event" /></dd>
                        </div>
                    </dl>

                    <x-event-actions :event="$event" mobile />
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
