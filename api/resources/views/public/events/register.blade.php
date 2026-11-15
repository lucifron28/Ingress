@extends('layouts.public')

@section('content')
    @php
        $eventRequiresStudent = $event->registration_eligibility === \App\Models\Event::ELIGIBILITY_STUDENT;
        $selectedCategory = $categories->firstWhere('id', (int) old('ticket_category_id', $selectedCategoryId));
        $selectedCategoryRequiresStudent = $selectedCategory?->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT;
        $hasStudentOnlyCategories = $categories->contains(fn ($category) => $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT);
    @endphp

    <nav class="public-breadcrumbs" aria-label="Breadcrumb">
        <a href="{{ route('public.events.show', $event->slug) }}">Back to event</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Registration</span>
    </nav>

    <header class="public-page-header public-page-header--compact">
        <p class="public-eyebrow">Event registration</p>
        <h1 class="public-page-title">Choose your ticket</h1>
    </header>

    <section class="public-panel public-registration-card" aria-labelledby="registration-event-title">
        <header class="public-registration-summary">
            <h2 id="registration-event-title">{{ $event->title }}</h2>
            <dl class="public-registration-summary__facts">
                <div>
                    <dt>Date and time (PHT)</dt>
                    <dd><time datetime="{{ \App\Support\EventSchedule::local($event->starts_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($event->starts_at)->format('M j, Y · g:i A') }}</time></dd>
                </div>
                <div>
                    <dt>Venue</dt>
                    <dd>{{ $event->venue->name }}</dd>
                </div>
            </dl>
        </header>

        @if ($errors->any())
            <div class="form-error-summary" role="alert" tabindex="-1" aria-labelledby="registration-errors-title">
                <h2 id="registration-errors-title">Please review your registration</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($event->isCancelled())
            <div class="public-notice public-notice--error" role="status">
                <strong>This event has been cancelled.</strong>
                <span>Registration is unavailable.</span>
            </div>
        @elseif (! $event->isRegistrationOpen())
            <div class="public-notice" role="status">
                <strong>Registration is unavailable.</strong>
                <span>Registration is not open, has closed, or this event has been finalized.</span>
            </div>
        @elseif (! $hasAvailableCategory)
            <div class="public-notice" role="status">
                <strong>No ticket categories are currently available for checkout.</strong>
            </div>
        @elseif ($categories->isEmpty())
            <div class="public-notice" role="status">
                <strong>No ticket categories are available.</strong>
                <span>Please check back with the event organizer.</span>
            </div>
        @else
            @if ($eventRequiresStudent)
                <div class="public-notice" role="note">
                    A Firebase-verified student account is required. Guests cannot register for this event.
                    @if ($requiresInstitutionalEmail)
                        New registrations must use an email ending in <strong>{{ '@'.$institutionalDomain }}</strong>.
                    @else
                        Personal email addresses are accepted under the current student access policy.
                    @endif
                    Email verification confirms mailbox access, not current enrollment.
                </div>
            @else
                <div class="public-notice" role="note">
                    Public tickets use guest checkout, so no account is required. Student-only tickets require verified student sign-in.
                    @if ($event->canOfferSimulatedPayments())
                        Paid checkout is simulated; no real money is charged and no card details are collected.
                    @endif
                </div>
            @endif

            <form id="public-registration-form" class="public-form" method="POST" action="{{ route('public.events.register.store', $event->slug) }}" autocomplete="on" data-event-requires-student="{{ $eventRequiresStudent ? 'true' : 'false' }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                <fieldset class="public-ticket-choices" @if ($errors->has('ticket_category_id')) aria-describedby="ticket-category-error" @endif>
                    <legend class="form-label">Ticket category <span class="required-indicator" aria-hidden="true">*</span></legend>
                    <div class="public-ticket-choices__list">
                        @foreach ($categories as $category)
                            @php
                                $categoryRemaining = $category->remainingCapacity();
                                $unavailable = ($category->price_minor > 0 && ! $event->canOfferSimulatedPayments()) || $categoryRemaining === 0;
                                $categoryId = 'ticket-category-'.$category->id;
                                $isSelected = (string) old('ticket_category_id', $selectedCategoryId) === (string) $category->id && ! $unavailable;
                            @endphp
                            <label class="public-ticket-choice{{ $unavailable ? ' public-ticket-choice--unavailable' : '' }}" for="{{ $categoryId }}">
                                <input
                                    id="{{ $categoryId }}"
                                    type="radio"
                                    name="ticket_category_id"
                                    value="{{ $category->id }}"
                                    data-student-only="{{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'true' : 'false' }}"
                                    @if (! $unavailable) required @endif
                                    @checked($isSelected)
                                    @disabled($unavailable)
                                >
                                <span class="public-ticket-choice__copy">
                                    <span class="public-ticket-choice__topline">
                                        <span class="public-ticket-choice__name">{{ $category->name }}</span>
                                        <strong>{{ $category->price_minor === 0 ? 'Free' : '₱'.number_format($category->price_minor / 100, 2) }}</strong>
                                    </span>
                                    <span class="public-ticket-choice__details">
                                        <span>{{ $category->eligibility === \App\Models\TicketCategory::ELIGIBILITY_STUDENT ? 'Student account required' : 'Open to the public' }}</span>
                                        @if ($categoryRemaining === 0)
                                            <span class="badge badge-error">Sold out</span>
                                        @elseif ($categoryRemaining !== null)
                                            <span>{{ $categoryRemaining }} {{ \Illuminate\Support\Str::plural('place', $categoryRemaining) }} remaining</span>
                                        @else
                                            <span>Available</span>
                                        @endif
                                        @if ($category->price_minor > 0 && ! $event->canOfferSimulatedPayments())
                                            <span class="badge badge-neutral">Paid checkout unavailable</span>
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('ticket_category_id')
                        <p class="field-error" id="ticket-category-error" role="alert">{{ $message }}</p>
                    @enderror
                </fieldset>

                @if (! $eventRequiresStudent)
                    @if ($hasStudentOnlyCategories)
                        <p id="student-category-note" class="public-notice" role="status" @if (! $selectedCategoryRequiresStudent) hidden @endif>
                            Student-only tickets require a Firebase-verified student account.
                            @if ($requiresInstitutionalEmail)
                                New registrations must use an email ending in <strong>{{ '@'.$institutionalDomain }}</strong>.
                            @else
                                Personal email addresses are accepted under the current student access policy.
                            @endif
                            Guest contact details are not needed. Email verification confirms mailbox access, not current enrollment.
                        </p>
                    @endif
                    <div id="guest-checkout-fields" @if ($selectedCategoryRequiresStudent) hidden @endif>
                        <div class="form-group">
                            <label class="form-label" for="guest_name">Attendee name <span class="required-indicator" aria-hidden="true">*</span></label>
                            <input class="form-input" id="guest_name" name="guest_name" type="text" maxlength="255" value="{{ old('guest_name') }}" autocomplete="name" @if (! $selectedCategoryRequiresStudent) required @endif @if ($errors->has('guest_name')) aria-invalid="true" aria-describedby="guest-name-error" @endif>
                            @error('guest_name')
                                <p class="field-error" id="guest-name-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="guest_email">Attendee email <span class="required-indicator" aria-hidden="true">*</span></label>
                            <input class="form-input" id="guest_email" name="guest_email" type="email" maxlength="255" value="{{ old('guest_email') }}" autocomplete="email" @if (! $selectedCategoryRequiresStudent) required @endif @if ($errors->has('guest_email')) aria-invalid="true" aria-describedby="guest-email-error" @endif>
                            @error('guest_email')
                                <p class="field-error" id="guest-email-error" role="alert">{{ $message }}</p>
                            @enderror
                            <p class="form-help">Guest contact details help issue and manage your ticket. They do not verify identity.</p>
                        </div>
                    </div>
                @endif

                <p class="public-privacy-note">Your name and email are used to issue and manage this event registration. The admission QR contains no personal or payment information.</p>
                <div class="public-form-actions">
                    <x-ui.button type="submit"><span>Continue</span></x-ui.button>
                    <x-ui.button :href="route('public.events.show', $event->slug)" variant="secondary"><span>Back to event</span></x-ui.button>
                </div>
            </form>
        @endif
    </section>

    @if (! $eventRequiresStudent && $hasStudentOnlyCategories)
        <script>
            (() => {
                const form = document.getElementById('public-registration-form');
                const choices = form?.querySelectorAll('[name="ticket_category_id"]');
                const guestFields = form?.querySelector('#guest-checkout-fields');
                const studentNote = form?.querySelector('#student-category-note');
                const guestName = form?.querySelector('#guest_name');
                const guestEmail = form?.querySelector('#guest_email');

                if (!choices?.length || !guestFields || !studentNote) return;

                const updateCategoryDetails = () => {
                    const selected = form.querySelector('[name="ticket_category_id"]:checked');
                    const studentOnly = selected?.dataset.studentOnly === 'true';
                    guestFields.hidden = studentOnly;
                    studentNote.hidden = !studentOnly;

                    if (guestName) guestName.required = !studentOnly;
                    if (guestEmail) guestEmail.required = !studentOnly;
                };

                choices.forEach((choice) => choice.addEventListener('change', updateCategoryDetails));
                updateCategoryDetails();
            })();
        </script>
    @endif
@endsection
