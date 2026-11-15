@extends('layouts.public')

@section('content')
    @php
        $hasStudentOnlyCategories = $event->ticketCategories->contains(fn ($category) => $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT);
    @endphp

    <nav class="public-breadcrumbs" aria-label="Breadcrumb">
        <a href="{{ route('public.events.index') }}">Campus events</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $event->title }}</span>
    </nav>

    <div class="public-event-detail-layout">
        <article class="public-panel public-event-info" aria-labelledby="event-title">
            <header class="public-event-heading">
                <p class="public-eyebrow">Event details</p>
                <div class="public-badge-row">
                    @if ($event->isCancelled())
                        <span class="badge badge-error">Cancelled</span>
                    @endif
                    <span class="badge badge-neutral">
                        {{ $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT ? 'Student account required' : 'Open event' }}
                    </span>
                </div>
                <h1 id="event-title" class="public-event-title">{{ $event->title }}</h1>
            </header>

            <dl class="public-detail-facts">
                <div>
                    <dt>Date and time (PHT)</dt>
                    <dd>
                        <time datetime="{{ \App\Support\EventSchedule::local($event->starts_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->starts_at)->format('l, F j, Y · g:i A') }}</time>
                        <span aria-hidden="true">to</span>
                        <time datetime="{{ \App\Support\EventSchedule::local($event->ends_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->ends_at)->format('l, F j, Y · g:i A') }}</time>
                    </dd>
                </div>
                <div>
                    <dt>Venue</dt>
                    <dd>
                        <span>{{ $event->venue->name }}</span>
                        @if ($event->venue->location)
                            <span class="public-secondary-text">{{ $event->venue->location }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($event->description)
                <div class="public-event-description">{{ $event->description }}</div>
            @endif

            <section class="public-eligibility-note" aria-labelledby="eligibility-heading">
                <h2 id="eligibility-heading">Who can register</h2>
                <p>{{ $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT ? 'This event requires a Firebase-verified student account.' : 'Public ticket categories are open to guests. You do not need an Ingress account for those tickets.' }}</p>
                @if ($event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT || $hasStudentOnlyCategories)
                    @if ($event->registration_eligibility !== \App\Models\Event::ELIGIBILITY_STUDENT)
                        <p>Student-only ticket categories require a Firebase-verified student account.</p>
                    @endif
                    @if ($requiresInstitutionalEmail)
                        <p>New registrations require an email ending in <strong>{{ '@'.$institutionalDomain }}</strong>.</p>
                    @else
                        <p>Personal email addresses are accepted under the current student access policy.</p>
                    @endif
                    <p>Email verification confirms mailbox access, not current enrollment.</p>
                @endif
            </section>
        </article>

        <aside class="public-panel public-ticket-panel" aria-labelledby="ticket-panel-heading">
            <header class="public-ticket-panel__heading">
                <p class="public-eyebrow">Registration</p>
                <h2 id="ticket-panel-heading">Ticket categories</h2>
                <p class="public-secondary-text">{{ $remainingPlaces }} of {{ $event->capacity }} event places remaining</p>
            </header>

            <div class="public-ticket-category-list">
                @forelse ($event->ticketCategories as $category)
                    @php
                        $categoryRemaining = $category->remainingCapacity();
                        $paidUnavailable = $category->price_minor > 0 && ! $event->canOfferSimulatedPayments();
                    @endphp
                    <article class="public-ticket-category">
                        <div class="public-ticket-category__heading">
                            <h3>{{ $category->name }}</h3>
                            <strong class="public-ticket-price">{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }}</strong>
                        </div>
                        <p class="public-secondary-text">
                            {{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'Student account required' : 'Open to the public' }}
                        </p>
                        @if ($categoryRemaining === 0)
                            <p class="public-ticket-category__availability"><span class="badge badge-error">Sold out</span></p>
                        @elseif ($categoryRemaining !== null)
                            <p class="public-ticket-category__availability">{{ $categoryRemaining }} {{ \Illuminate\Support\Str::plural('place', $categoryRemaining) }} remaining in this category</p>
                        @else
                            <p class="public-ticket-category__availability">Available</p>
                        @endif
                        @if ($category->price_minor > 0)
                            <p class="public-ticket-payment-note">
                                {{ $event->canOfferSimulatedPayments() ? 'Simulated checkout. No real money is charged.' : 'Paid checkout is not available.' }}
                            </p>
                        @endif
                        @if ($paidUnavailable)
                            <span class="visually-hidden">This paid category cannot currently be selected.</span>
                        @endif
                    </article>
                @empty
                    <p class="public-secondary-text">No ticket categories are available.</p>
                @endforelse
            </div>

            @if ($event->isCancelled())
                <div class="public-registration-status public-registration-status--cancelled" role="status">
                    <strong>Registration unavailable</strong>
                    <span>This event has been cancelled.</span>
                </div>
            @elseif (! $hasAvailableCategory)
                <div class="public-registration-status" role="status">
                    <strong>Tickets unavailable</strong>
                    <span>No ticket categories can currently be checked out.</span>
                </div>
            @elseif (! $registrationOpen)
                <div class="public-registration-status" role="status">
                    <strong>Registration closed</strong>
                    <span>Registration is not open yet, has closed, or the event is full.</span>
                </div>
            @elseif ($event->ticketCategories->isNotEmpty())
                <div class="public-registration-status public-registration-status--open" role="status">
                    <strong>Registration open</strong>
                    <span>{{ $remainingPlaces }} event {{ \Illuminate\Support\Str::plural('place', $remainingPlaces) }} remaining.</span>
                </div>
                <x-ui.button :href="route('public.events.register', $event->slug)" class="public-ticket-panel__action">
                    <span>Choose a ticket</span>
                </x-ui.button>
            @endif
        </aside>
    </div>
@endsection
