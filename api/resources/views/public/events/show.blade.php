@extends('layouts.public')

@section('content')
    @php
        $hasStudentOnlyCategories = $event->ticketCategories->contains(fn ($category) => $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT);
    @endphp
    <nav class="event-breadcrumbs" aria-label="Breadcrumb">
        <a href="{{ route('public.events.index') }}">Campus events</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $event->title }}</span>
    </nav>

    <div class="event-detail-layout">
        <article class="event-detail-card" aria-labelledby="event-title">
            <header class="event-detail-heading">
                <p class="eyebrow">Event details</p>
                <div class="event-detail-badges">
                    @if ($event->isCancelled())
                        <span class="tag event-cancelled-badge">Cancelled</span>
                    @endif
                    <span class="tag">
                        {{ $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT ? 'Student account required' : 'Public event' }}
                    </span>
                </div>
                <h1 id="event-title">{{ $event->title }}</h1>
            </header>

            <dl class="event-detail-facts">
                <div class="event-detail-fact">
                    <dt>Date and time (PHT)</dt>
                    <dd>
                        <time datetime="{{ \App\Support\EventSchedule::local($event->starts_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->starts_at)->format('l, F j, Y · g:i A') }}</time>
                        <span aria-hidden="true">to</span>
                        <time datetime="{{ \App\Support\EventSchedule::local($event->ends_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->ends_at)->format('l, F j, Y · g:i A') }}</time>
                    </dd>
                </div>
                <div class="event-detail-fact">
                    <dt>Venue</dt>
                    <dd>
                        <span>{{ $event->venue->name }}</span>
                        @if ($event->venue->location)
                            <span class="meta">{{ $event->venue->location }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($event->description)
                <div class="event-description">{{ $event->description }}</div>
            @endif

            <p class="event-eligibility-note" role="note">
                <strong>Who can register</strong>
                <span>{{ $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT ? 'This event requires a Firebase-verified student account.' : 'Public categories are open to guests. You do not need an Ingress account for those tickets.' }}</span>
                @if ($event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT || $hasStudentOnlyCategories)
                    @if ($event->registration_eligibility !== \App\Models\Event::ELIGIBILITY_STUDENT)
                        <span>Student-only ticket categories require a Firebase-verified student account.</span>
                    @endif
                    @if ($requiresInstitutionalEmail)
                        <span>New registrations require an email ending in {{ '@'.$institutionalDomain }}.</span>
                    @else
                        <span>Personal email addresses are accepted under the current student access policy.</span>
                    @endif
                    <span>Email verification confirms mailbox access, not current enrollment.</span>
                @endif
            </p>
        </article>

        <aside class="ticket-panel" aria-labelledby="ticket-panel-heading">
            <header class="ticket-panel-heading">
                <p class="eyebrow">Registration</p>
                <h2 id="ticket-panel-heading">Ticket categories</h2>
                <p class="meta">{{ $remainingPlaces }} of {{ $event->capacity }} event places remaining</p>
            </header>

            <div class="ticket-category-list">
                @forelse ($event->ticketCategories as $category)
                    @php $categoryRemaining = $category->remainingCapacity(); @endphp
                    <article class="ticket-category">
                        <div class="ticket-category-heading">
                            <h3>{{ $category->name }}</h3>
                            <span class="ticket-category-price">{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }}</span>
                        </div>
                        <p class="ticket-category-eligibility">
                            {{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'Student account required' : 'Open to the public' }}
                        </p>
                        @if ($categoryRemaining !== null)
                            <p class="ticket-category-availability">
                                @if ($categoryRemaining === 0)
                                    <span class="ticket-category-sold-out">Sold out</span>
                                @else
                                    <strong>{{ $categoryRemaining }}</strong> category {{ \Illuminate\Support\Str::plural('place', $categoryRemaining) }} remaining
                                @endif
                            </p>
                        @endif
                        @if ($category->price_minor > 0)
                            <p class="ticket-payment-note">{{ $event->canOfferSimulatedPayments() ? 'Simulated payment · No real money is charged.' : 'Paid checkout is not available.' }}</p>
                        @endif
                    </article>
                @empty
                    <p class="meta">No ticket categories are available.</p>
                @endforelse
            </div>

            @if ($event->isCancelled())
                <div class="registration-status registration-status-cancelled" role="status">
                    <strong>Registration unavailable</strong>
                    <span>This event has been cancelled.</span>
                </div>
            @elseif (! $hasAvailableCategory)
                <div class="registration-status registration-status-unavailable" role="status">
                    <strong>Tickets unavailable</strong>
                    <span>No available ticket categories can be checked out.</span>
                </div>
            @elseif (! $registrationOpen)
                <div class="registration-status registration-status-unavailable" role="status">
                    <strong>Registration closed</strong>
                    <span>Registration is not open yet, has closed, or the event is full.</span>
                </div>
            @elseif ($event->ticketCategories->isNotEmpty())
                <div class="registration-status registration-status-open" role="status">
                    <strong>Registration open</strong>
                    <span>{{ $remainingPlaces }} event {{ \Illuminate\Support\Str::plural('place', $remainingPlaces) }} remaining.</span>
                </div>
                <a class="button" href="{{ route('public.events.register', $event->slug) }}">Choose a ticket</a>
            @endif
        </aside>
    </div>
@endsection
