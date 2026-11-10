@extends('layouts.public')

@section('content')
    <div style="margin-bottom: 1.5rem;">
        <p class="eyebrow">Campus events</p>
        <h1>Find your next event.</h1>
        <p class="lede">Browse public events and see ticket availability before you register. Public tickets support guest checkout; student-only events require a verified MSEUF student account.</p>
    </div>

    <div class="grid">
        @forelse ($events as $event)
            @php
                $remaining = max(0, $event->capacity - (int) $event->confirmed_registrations_count);
                $hasAvailableCategory = $event->ticketCategories->contains(fn ($category) => $category->price_minor === 0 && $category->remainingCapacity() !== 0);
                $registrationOpen = $event->isRegistrationOpen() && $remaining > 0 && $hasAvailableCategory;
            @endphp
            <article class="card stack">
                <div>
                    @if ($event->isCancelled()) <span class="tag">Cancelled</span>
                    @elseif ($event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT) <span class="tag">MSEUF students</span>
                    @else <span class="tag">Public event</span> @endif
                </div>
                <div>
                    <h2 style="font-size: 1.35rem; margin-bottom: .4rem;">{{ $event->title }}</h2>
                    <p class="meta">{{ $event->starts_at->format('M j, Y · g:i A') }}–{{ $event->ends_at->format('g:i A') }} · {{ $event->venue->name }}</p>
                    @if ($event->description)<p>{{ \Illuminate\Support\Str::limit($event->description, 180) }}</p>@endif
                    @if ($event->ticketCategories->isNotEmpty())
                        <p class="meta">Tickets: @foreach ($event->ticketCategories as $category){{ $category->name }}{{ $category->price_minor === 0 ? ' · Free' : ' · ₱'.number_format($category->price_minor / 100, 2) }}@if (! $loop->last), @endif @endforeach</p>
                    @endif
                </div>
                <div>
                    <p class="meta" style="margin-bottom: .8rem;">
                        @if ($event->isCancelled()) Registration unavailable.
                        @elseif ($remaining === 0) Sold out.
                        @elseif (! $hasAvailableCategory) No free ticket categories are available.
                        @elseif (! $registrationOpen) Registration closed or not open yet.
                        @else {{ $remaining }} event {{ \Illuminate\Support\Str::plural('place', $remaining) }} remaining. @endif
                    </p>
                    <a class="button {{ $registrationOpen ? '' : 'secondary' }}" href="{{ route('public.events.show', $event->slug) }}">View event</a>
                </div>
            </article>
        @empty
            <div class="card"><h2>No upcoming events</h2><p class="meta">Published events will appear here.</p></div>
        @endforelse
    </div>
@endsection
