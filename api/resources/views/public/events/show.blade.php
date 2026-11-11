@extends('layouts.public')

@section('content')
    <p class="eyebrow"><a href="{{ route('public.events.index') }}">Campus events</a> / Event details</p>
    <div class="grid" style="grid-template-columns: minmax(0, 1.5fr) minmax(17rem, .8fr); align-items: start;">
        <article class="card stack">
            @if ($event->isCancelled()) <span class="tag">Cancelled</span> @endif
            <h1>{{ $event->title }}</h1>
            <p class="meta">{{ $event->starts_at->format('l, F j, Y · g:i A') }}–{{ $event->ends_at->format('g:i A') }}</p>
            <p><strong>{{ $event->venue->name }}</strong>@if ($event->venue->location) · {{ $event->venue->location }}@endif</p>
            @if ($event->description)<div style="white-space: pre-line;">{{ $event->description }}</div>@endif
            <div class="notice">{{ $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT ? 'This event is reserved for verified MSEUF students.' : 'Public registration is open to guests. You do not need an Ingress account for public tickets.' }}</div>
        </article>

        <aside class="card stack">
            <div>
                <p class="eyebrow">Ticket categories</p>
                <p class="meta">{{ $remainingPlaces }} of {{ $event->capacity }} event {{ \Illuminate\Support\Str::plural('place', $event->capacity) }} remaining</p>
            </div>
            @forelse ($event->ticketCategories as $category)
                @php $categoryRemaining = $category->remainingCapacity(); @endphp
                <div style="border-top: 1px solid var(--line); padding-top: .8rem;">
                    <strong>{{ $category->name }}</strong>
                    <p class="meta" style="margin: .2rem 0;">{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }} · {{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'Verified students only' : 'Public' }}</p>
                    @if ($categoryRemaining !== null)<p class="meta" style="margin: .2rem 0;">{{ $categoryRemaining }} category {{ \Illuminate\Support\Str::plural('place', $categoryRemaining) }} remaining</p>@endif
                    @if ($category->price_minor > 0)
                        <p class="meta">{{ $event->canOfferSimulatedPayments() ? 'Simulated payment · No real money is charged.' : 'Paid checkout is not available.' }}</p>
                    @endif
                </div>
            @empty
                <p class="meta">No ticket categories are available.</p>
            @endforelse

            @if ($event->isCancelled())
                <p class="notice">This event has been cancelled. Registration is unavailable.</p>
            @elseif (! $hasAvailableCategory)
                <p class="notice">No ticket categories are available. Sold-out categories cannot be checked out.</p>
            @elseif (! $registrationOpen)
                <p class="notice">Registration is not open, has closed, or the event is full.</p>
            @elseif ($event->ticketCategories->isNotEmpty())
                <a class="button" href="{{ route('public.events.register', $event->slug) }}">Choose a ticket</a>
            @endif
        </aside>
    </div>
@endsection
