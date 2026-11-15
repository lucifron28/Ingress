@extends('layouts.public')

@section('content')
    @php
        $eventRequiresStudent = $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT;
        $selectedCategory = $categories->firstWhere('id', (int) $selectedCategoryId);
        $selectedCategoryRequiresStudent = $selectedCategory?->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT;
        $hasStudentOnlyCategories = $categories->contains(fn ($category) => $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT);
    @endphp
    <p class="eyebrow"><a href="{{ route('public.events.show', $event->slug) }}">{{ $event->title }}</a> / Registration</p>
    <section class="card" style="max-width: 42rem; margin: 0 auto;">
        <h1 style="font-size: 2rem;">Register for {{ $event->title }}</h1>
        <p class="meta">{{ \App\Support\EventSchedule::local($event->starts_at)->format('M j, Y · g:i A') }} PHT · {{ $event->venue->name }}</p>

        @if ($errors->any())
            <div class="error" role="alert"><ul style="margin: 0; padding-left: 1.2rem;">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($event->isCancelled())
            <div class="notice">This event has been cancelled. Registration is unavailable.</div>
        @elseif (! $event->isRegistrationOpen())
            <div class="notice">Registration is not open, has closed, or this event has been finalized.</div>
        @elseif (! $hasAvailableCategory)
            <div class="notice">No ticket categories are currently available for checkout.</div>
        @elseif ($categories->isEmpty())
            <div class="notice">No ticket categories are available for registration.</div>
        @else
            @if ($eventRequiresStudent)
                <div class="notice">
                    A Firebase-verified student account is required. Guests cannot register for this event.
                    @if ($requiresInstitutionalEmail)
                        New registrations must use an email ending in {{ '@'.$institutionalDomain }}.
                    @else
                        Personal email addresses are accepted under the current student access policy.
                    @endif
                    Email verification confirms mailbox access, not current enrollment.
                </div>
            @else
                <div class="notice">Public tickets use guest checkout. No account is required. Student-only categories require verified student sign-in. @if ($event->canOfferSimulatedPayments()) Paid checkout is simulated; no real money is charged and no card details are collected. @endif</div>
            @endif

            <form id="public-registration-form" method="POST" action="{{ route('public.events.register.store', $event->slug) }}" autocomplete="on" data-event-requires-student="{{ $eventRequiresStudent ? 'true' : 'false' }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                <div class="field">
                    <label for="ticket_category_id">Ticket category</label>
                    <select id="ticket_category_id" name="ticket_category_id" required>
                        @foreach ($categories as $category)
                            @php
                                $categoryRemaining = $category->remainingCapacity();
                                $unavailable = ($category->price_minor > 0 && ! $event->canOfferSimulatedPayments()) || $categoryRemaining === 0;
                                $categoryLabel = $category->name.' · '.($category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2));
                                if ($category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT) $categoryLabel .= ' · Student account required';
                                if ($categoryRemaining === 0) $categoryLabel .= ' · Sold out';
                                elseif ($category->price_minor > 0 && $event->canOfferSimulatedPayments()) $categoryLabel .= ' · Simulated checkout';
                                elseif ($category->price_minor > 0) $categoryLabel .= ' · Payment unavailable';
                            @endphp
                            <option value="{{ $category->id }}" data-student-only="{{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'true' : 'false' }}" {{ (string) old('ticket_category_id', $selectedCategoryId) === (string) $category->id ? 'selected' : '' }} {{ $unavailable ? 'disabled' : '' }}>{{ $categoryLabel }}</option>
                        @endforeach
                    </select>
                </div>

                @if (! $eventRequiresStudent)
                    @if ($hasStudentOnlyCategories)
                        <p id="student-category-note" class="notice" role="status" @if (! $selectedCategoryRequiresStudent) hidden @endif>
                            Student-only tickets require a Firebase-verified student account.
                            @if ($requiresInstitutionalEmail)
                                New registrations must use an email ending in {{ '@'.$institutionalDomain }}.
                            @else
                                Personal email addresses are accepted under the current student access policy.
                            @endif
                            Guest contact details are not needed. Email verification confirms mailbox access, not current enrollment.
                        </p>
                    @endif
                    <div id="guest-checkout-fields" @if ($selectedCategoryRequiresStudent) hidden @endif>
                        <div class="field">
                            <label for="guest_name">Attendee name</label>
                            <input id="guest_name" name="guest_name" type="text" maxlength="255" value="{{ old('guest_name') }}" autocomplete="name">
                        </div>
                        <div class="field">
                            <label for="guest_email">Attendee email</label>
                            <input id="guest_email" name="guest_email" type="email" maxlength="255" value="{{ old('guest_email') }}" autocomplete="email">
                            <span class="meta">Required for public guest checkout. Guest contact details do not verify identity.</span>
                        </div>
                    </div>
                @endif

                <p class="meta">Your name and email are used to issue and manage this event registration. The admission QR contains no personal or payment information.</p>
                <div style="display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.25rem;">
                    <button class="button" type="submit">Continue</button>
                    <a class="button secondary" href="{{ route('public.events.show', $event->slug) }}">Back to event</a>
                </div>
            </form>
        @endif
    </section>
    @if (! $eventRequiresStudent && $hasStudentOnlyCategories)
        <script>
            (() => {
                const form = document.getElementById('public-registration-form');
                const category = form?.querySelector('#ticket_category_id');
                const guestFields = form?.querySelector('#guest-checkout-fields');
                const studentNote = form?.querySelector('#student-category-note');

                if (!category || !guestFields || !studentNote) return;

                const updateCategoryDetails = () => {
                    const studentOnly = category.selectedOptions[0]?.dataset.studentOnly === 'true';
                    guestFields.hidden = studentOnly;
                    studentNote.hidden = !studentOnly;
                };

                category.addEventListener('change', updateCategoryDetails);
                updateCategoryDetails();
            })();
        </script>
    @endif
@endsection
