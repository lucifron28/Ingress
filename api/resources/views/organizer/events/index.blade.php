@extends('layouts.app')

@section('content')
<x-page-header title="All Events" subtitle="Manage event lifecycle, capacity, and admission access">
    <x-slot:actions>
        <x-ui.button :href="route('organizer.events.create')">
            <x-icon name="plus" size="18" />
            <span>Create Event</span>
        </x-ui.button>
    </x-slot:actions>
</x-page-header>

<section class="events-listing card" aria-label="All managed events">
    <div class="events-table-wrap" role="region" aria-label="All events table" tabindex="0">
        <table class="events-table">
            <caption class="visually-hidden">Events, venues, registration periods, schedules, capacities, and management actions</caption>
            <thead>
                <tr>
                    <th scope="col">Event</th>
                    <th scope="col">Registration Window</th>
                    <th scope="col">Event Schedule</th>
                    <th scope="col">Capacity</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="events-table__actions-heading">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    @php
                        $startsAt = $event->starts_at;
                        $endsAt = $event->ends_at;
                        $registrationOpensAt = $event->registration_opens_at;
                        $registrationClosesAt = $event->registration_closes_at;

                        if ($startsAt->isSameDay($endsAt)) {
                            $eventDateRange = $startsAt->format('F j, Y');
                        } elseif ($startsAt->format('F Y') === $endsAt->format('F Y')) {
                            $eventDateRange = $startsAt->format('F j').'–'.$endsAt->format('j, Y');
                        } elseif ($startsAt->format('Y') === $endsAt->format('Y')) {
                            $eventDateRange = $startsAt->format('M j').'–'.$endsAt->format('M j, Y');
                        } else {
                            $eventDateRange = $startsAt->format('M j, Y').'–'.$endsAt->format('M j, Y');
                        }

                        if ($registrationOpensAt->format('F Y') === $registrationClosesAt->format('F Y')) {
                            $registrationDateRange = $registrationOpensAt->format('M j').'–'.$registrationClosesAt->format('j, Y');
                        } elseif ($registrationOpensAt->format('Y') === $registrationClosesAt->format('Y')) {
                            $registrationDateRange = $registrationOpensAt->format('M j').'–'.$registrationClosesAt->format('M j, Y');
                        } else {
                            $registrationDateRange = $registrationOpensAt->format('M j, Y').'–'.$registrationClosesAt->format('M j, Y');
                        }
                    @endphp
                    <tr>
                        <th scope="row" class="events-table__event">
                            <a href="{{ route('organizer.events.show', $event) }}">{{ $event->title }}</a>
                            <span>{{ $event->venue->name }}</span>
                        </th>
                        <td>
                            <div class="events-table__date">{{ $registrationDateRange }}</div>
                            <div class="events-table__supporting">{{ $registrationOpensAt->format('g:i A') }} – {{ $registrationClosesAt->format('g:i A') }}</div>
                            <div class="events-table__supporting">Registration period</div>
                        </td>
                        <td>
                            <div class="events-table__date">{{ $eventDateRange }}</div>
                            <div class="events-table__supporting">{{ $startsAt->format('g:i A') }} – {{ $endsAt->format('g:i A') }}</div>
                        </td>
                        <td class="events-table__capacity mono">{{ number_format($event->capacity) }}</td>
                        <td><x-event-status-badge :event="$event" /></td>
                        <td>
                            <div class="events-table__actions">
                                <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary events-table__action">
                                    <x-icon name="eye" size="16" />
                                    <span>Overview</span>
                                </a>
                                <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary events-table__action">
                                    <x-icon name="pencil" size="16" />
                                    <span>Edit</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="events-table__empty">No events found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="events-mobile-list">
        @forelse ($events as $event)
            @php
                $startsAt = $event->starts_at;
                $endsAt = $event->ends_at;
                $registrationOpensAt = $event->registration_opens_at;
                $registrationClosesAt = $event->registration_closes_at;

                if ($startsAt->isSameDay($endsAt)) {
                    $eventDateRange = $startsAt->format('F j, Y');
                } elseif ($startsAt->format('F Y') === $endsAt->format('F Y')) {
                    $eventDateRange = $startsAt->format('F j').'–'.$endsAt->format('j, Y');
                } elseif ($startsAt->format('Y') === $endsAt->format('Y')) {
                    $eventDateRange = $startsAt->format('M j').'–'.$endsAt->format('M j, Y');
                } else {
                    $eventDateRange = $startsAt->format('M j, Y').'–'.$endsAt->format('M j, Y');
                }

                if ($registrationOpensAt->format('F Y') === $registrationClosesAt->format('F Y')) {
                    $registrationDateRange = $registrationOpensAt->format('M j').'–'.$registrationClosesAt->format('j, Y');
                } elseif ($registrationOpensAt->format('Y') === $registrationClosesAt->format('Y')) {
                    $registrationDateRange = $registrationOpensAt->format('M j').'–'.$registrationClosesAt->format('M j, Y');
                } else {
                    $registrationDateRange = $registrationOpensAt->format('M j, Y').'–'.$registrationClosesAt->format('M j, Y');
                }
            @endphp
            <article class="events-mobile-card">
                <header class="events-mobile-card__header">
                    <div>
                        <h2><a href="{{ route('organizer.events.show', $event) }}">{{ $event->title }}</a></h2>
                        <p>{{ $event->venue->name }}</p>
                    </div>
                    <x-event-status-badge :event="$event" />
                </header>
                <dl class="events-mobile-card__details">
                    <div>
                        <dt>Event schedule</dt>
                        <dd>{{ $eventDateRange }}</dd>
                        <dd class="events-table__supporting">{{ $startsAt->format('g:i A') }} – {{ $endsAt->format('g:i A') }}</dd>
                    </div>
                    <div>
                        <dt>Registration</dt>
                        <dd>{{ $registrationDateRange }}</dd>
                        <dd class="events-table__supporting">{{ $registrationOpensAt->format('g:i A') }} – {{ $registrationClosesAt->format('g:i A') }}</dd>
                    </div>
                    <div>
                        <dt>Capacity</dt>
                        <dd class="events-mobile-card__capacity mono">{{ number_format($event->capacity) }}</dd>
                    </div>
                </dl>
                <div class="events-mobile-card__actions">
                    <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary events-table__action">
                        <x-icon name="eye" size="16" />
                        <span>Overview</span>
                    </a>
                    <a href="{{ route('organizer.events.edit', $event) }}" class="btn-secondary events-table__action">
                        <x-icon name="pencil" size="16" />
                        <span>Edit</span>
                    </a>
                </div>
            </article>
        @empty
            <p class="events-table__empty">No events found.</p>
        @endforelse
    </div>

    @if ($events->hasPages())
        <div class="events-listing__pagination">
            {{ $events->links() }}
        </div>
    @endif
</section>
@endsection
