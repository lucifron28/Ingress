@extends('layouts.public')

@section('content')
    <header class="public-page-header">
        <p class="public-eyebrow">Campus events</p>
        <h1 class="public-page-title">Discover what's happening.</h1>
        <p class="public-page-lede">Find upcoming events at MSEUF, explore the details, and choose the ticket that fits you.</p>
    </header>

    <section class="public-event-grid" aria-label="Upcoming campus events">
        @forelse ($events as $event)
            @php
                $remaining = max(0, $event->capacity - (int) $event->confirmed_registrations_count - (int) $event->active_checkout_orders_count);
                $eventCanAcceptRegistration = ! $event->isCancelled() && $event->isRegistrationOpen() && $remaining > 0;
                $availableCategories = $eventCanAcceptRegistration
                    ? $event->ticketCategories->filter(fn ($category) => ($category->price_minor === 0 || $event->canOfferSimulatedPayments()) && $category->remainingCapacity() !== 0)
                    : collect();
                $registrationOpen = $availableCategories->isNotEmpty();
                $hasFreeCategory = $availableCategories->contains(fn ($category) => $category->price_minor === 0);
                $lowestPaidPrice = $availableCategories->filter(fn ($category) => $category->price_minor > 0)->min('price_minor');
                $hasStudentOnlyCategory = $availableCategories->contains(fn ($category) => $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT);
                $firstAvailableCategory = $availableCategories->first();
                $additionalCategoryCount = max(0, $availableCategories->count() - 1);
            @endphp

            <article class="public-event-card">
                <div class="public-event-card__main">
                    <h2 class="public-event-card__title">
                        <a href="{{ route('public.events.show', $event->slug) }}">{{ $event->title }}</a>
                    </h2>
                    <p class="public-event-card__eligibility">
                        @if ($event->isCancelled())
                            <span class="badge badge-error">Cancelled</span>
                        @endif
                        @if ($event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT)
                            <span class="badge badge-info">Event eligibility: student account required</span>
                        @else
                            <span class="badge badge-neutral">Event eligibility: open to the public</span>
                        @endif
                    </p>

                    <dl class="public-event-facts">
                        <div class="public-event-fact">
                            <dt>Date and time (PHT)</dt>
                            <dd>
                                <time datetime="{{ \App\Support\EventSchedule::local($event->starts_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->starts_at)->format('M j, Y · g:i A') }}</time>
                                <span aria-hidden="true">to</span>
                                <time datetime="{{ \App\Support\EventSchedule::local($event->ends_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->ends_at)->format('M j, Y · g:i A') }}</time>
                            </dd>
                        </div>
                        <div class="public-event-fact">
                            <dt>Venue</dt>
                            <dd>{{ $event->venue->name }}</dd>
                        </div>
                    </dl>

                    @if ($event->description)
                        <p class="public-event-card__description">{{ \Illuminate\Support\Str::limit($event->description, 180) }}</p>
                    @endif

                    <div class="public-event-card__prices" aria-label="Ticket price and availability">
                        <span class="public-field-label">Available tickets</span>
                        <p>
                            @if ($event->isCancelled())
                                Registration unavailable
                            @elseif (! $event->isRegistrationOpen())
                                Registration not open
                            @elseif ($remaining === 0)
                                Sold out
                            @elseif ($availableCategories->isEmpty())
                                No tickets available
                            @else
                                @if ($hasFreeCategory)
                                    Free tickets available
                                @endif
                                @if ($hasFreeCategory && $lowestPaidPrice !== null)
                                    <span aria-hidden="true"> · </span>
                                @endif
                                @if ($lowestPaidPrice !== null)
                                    Paid tickets from ₱{{ number_format($lowestPaidPrice / 100, 2) }}
                                @endif
                            @endif
                        </p>
                        @if ($firstAvailableCategory)
                            <span class="public-event-card__price-note">
                                {{ $firstAvailableCategory->name }}@if ($additionalCategoryCount > 0) and {{ $additionalCategoryCount }} more available {{ \Illuminate\Support\Str::plural('category', $additionalCategoryCount) }}@endif
                            </span>
                        @endif
                        @if ($hasStudentOnlyCategory)
                            <span class="public-event-card__price-note">Some available tickets require a student account.</span>
                        @endif
                    </div>
                </div>

                <footer class="public-event-card__footer">
                    <p class="public-event-status" role="status">
                        @if ($event->isCancelled())
                            <span class="badge badge-error">Registration unavailable</span>
                        @elseif ($remaining === 0)
                            <span class="badge badge-neutral">Sold out</span>
                        @elseif ($availableCategories->isEmpty())
                            <span class="badge badge-neutral">No tickets available</span>
                        @elseif (! $event->isRegistrationOpen())
                            <span class="badge badge-neutral">Registration closed or not open yet</span>
                        @elseif ($registrationOpen)
                            <span class="badge badge-success">Registration open</span>
                            <span>{{ $remaining }} {{ \Illuminate\Support\Str::plural('place', $remaining) }} remaining</span>
                        @else
                            <span class="badge badge-neutral">Registration unavailable</span>
                        @endif
                    </p>
                    <x-ui.button :href="route('public.events.show', $event->slug)">
                        <span>View event</span>
                    </x-ui.button>
                </footer>
            </article>
        @empty
            <div class="public-empty-state">
                <h2>No upcoming events</h2>
                <p>Published campus events will appear here.</p>
            </div>
        @endforelse
    </section>
@endsection
