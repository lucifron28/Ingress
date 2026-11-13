@extends('layouts.public')

@section('content')
    <section class="events-intro" aria-labelledby="events-heading">
        <p class="eyebrow">Campus events</p>
        <h1 id="events-heading">Find your next event.</h1>
        <p class="lede">Browse public events and see ticket availability before you register. Public tickets support guest checkout; student-only events require a verified MSEUF student account.</p>
    </section>

    <div class="events-grid">
        @forelse ($events as $event)
            @php
                $remaining = max(0, $event->capacity - (int) $event->confirmed_registrations_count - (int) $event->active_checkout_orders_count);
                $hasAvailableCategory = $event->ticketCategories->contains(fn ($category) => ($category->price_minor === 0 || $event->canOfferSimulatedPayments()) && $category->remainingCapacity() !== 0);
                $registrationOpen = $event->isRegistrationOpen() && $remaining > 0 && $hasAvailableCategory;
            @endphp
            <article class="event-card">
                <div>
                    @if ($event->isCancelled())
                        <span class="tag event-tag event-tag-cancelled">Cancelled</span>
                    @elseif ($event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT)
                        <span class="tag event-tag">MSEUF students</span>
                    @else
                        <span class="tag event-tag">Public event</span>
                    @endif
                </div>
                <div class="event-card-content">
                    <h2>{{ $event->title }}</h2>
                    <div class="event-facts">
                        <div class="event-fact">
                            <span class="event-fact-label">Date and time</span>
                            <p class="event-fact-value">
                                <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('M j, Y · g:i A') }}</time>
                                <span>to</span>
                                <time datetime="{{ $event->ends_at->toIso8601String() }}">{{ $event->ends_at->format('M j, Y · g:i A') }}</time>
                            </p>
                        </div>
                        <div class="event-fact">
                            <span class="event-fact-label">Venue</span>
                            <p class="event-fact-value">{{ $event->venue->name }}</p>
                        </div>
                    </div>
                    @if ($event->description)
                        <p class="event-description">{{ \Illuminate\Support\Str::limit($event->description, 180) }}</p>
                    @endif
                    @if ($event->ticketCategories->isNotEmpty())
                        <div>
                            <span class="event-ticket-label">Ticket options</span>
                            <ul class="event-ticket-list">
                                @foreach ($event->ticketCategories as $category)
                                    <li>
                                        <span>{{ $category->name }}</span>
                                        <span class="event-ticket-price">{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                <div class="event-card-footer">
                    <p class="event-status {{ $registrationOpen ? 'event-status-open' : 'event-status-unavailable' }}" role="status">
                        @if ($event->isCancelled()) Registration unavailable.
                        @elseif ($remaining === 0) Sold out.
                        @elseif (! $hasAvailableCategory) No free ticket categories are available.
                        @elseif (! $registrationOpen) Registration closed or not open yet.
                        @else {{ $remaining }} event {{ \Illuminate\Support\Str::plural('place', $remaining) }} remaining. @endif
                    </p>
                    <a class="button" href="{{ route('public.events.show', $event->slug) }}">View event</a>
                </div>
            </article>
        @empty
            <div class="card events-empty"><h2>No upcoming events</h2><p class="meta">Published events will appear here.</p></div>
        @endforelse
    </div>
@endsection
