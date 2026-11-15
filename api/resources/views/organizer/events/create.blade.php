@extends('layouts.app', ['title' => 'Create event'])

@section('content')
@php
    $stepFields = [
        1 => ['title', 'description', 'venue_id', 'capacity'],
        2 => ['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at'],
        3 => ['registration_eligibility', 're_entry_allowed', 'ticket_transfers_allowed', 'identity_verification_level'],
    ];
    $initialStep = 1;
    $errorFields = $errors->getBag('default')->keys();
    foreach ($stepFields as $stepNumber => $fields) {
        if (array_intersect($fields, $errorFields) !== []) {
            $initialStep = $stepNumber;
            break;
        }
    }

    $openValue = old('registration_opens_at', \App\Support\EventSchedule::localInput(now()));
    $closeValue = old('registration_closes_at', \App\Support\EventSchedule::localInput(now()->addDay()));
    $startValue = old('starts_at', \App\Support\EventSchedule::localInput(\App\Support\EventSchedule::local(now()->addDays(2))->setTime(8, 0)));
    $endValue = old('ends_at', \App\Support\EventSchedule::localInput(\App\Support\EventSchedule::local(now()->addDays(2))->setTime(17, 0)));
    $selectedVenue = $venues->firstWhere('id', old('venue_id'));
    $eligibility = old('registration_eligibility', 'public');
    $eligibilityLabel = $eligibility === 'student' ? 'Students with verified Firebase accounts' : 'Public guest registration';
@endphp

<div class="event-create-shell">
    <header class="event-create-header">
        <div>
            <p class="event-create-eyebrow">EVENT SETUP</p>
            <h1>Create an event</h1>
            <p>Follow four short steps. You can review and change everything before saving the draft.</p>
        </div>
        <a href="{{ route('organizer.events.index') }}" class="btn-secondary">Cancel</a>
    </header>

    @if ($errors->any())
        <div id="event-create-errors" class="event-create-errors" role="alert" tabindex="-1">
            <strong>Some details need attention.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card event-create-card">
        <form method="POST" action="{{ route('organizer.events.store') }}" data-event-create-form data-start-step="{{ $initialStep }}">
            @csrf

            <ol class="event-create-progress" aria-label="Event setup steps" data-event-progress>
                <li data-step-indicator="1"><span>1</span><strong>Details</strong></li>
                <li data-step-indicator="2"><span>2</span><strong>Schedule</strong></li>
                <li data-step-indicator="3"><span>3</span><strong>Registration</strong></li>
                <li data-step-indicator="4"><span>4</span><strong>Review</strong></li>
            </ol>

            <p class="event-create-step-status" aria-live="polite" data-event-step-status>Step {{ $initialStep }} of 4</p>

            <section class="event-create-step" data-event-step="1" aria-labelledby="event-step-details">
                <h2 id="event-step-details" tabindex="-1">Event details</h2>
                <p class="event-create-intro">Start with the name and place attendees will recognize.</p>

                <div class="form-group">
                    <label for="title" class="form-label">Event name <span aria-hidden="true">*</span></label>
                    <input id="title" name="title" type="text" value="{{ old('title') }}" required maxlength="255" class="form-input" placeholder="e.g. Campus Intramurals — Demo" aria-describedby="title-help title-error" @if ($errors->has('title')) aria-invalid="true" @endif>
                    <p id="title-help" class="event-field-help">Use the title that should appear on the event page and tickets.</p>
                    @error('title')<p id="title-error" class="event-field-error">{{ $message }}</p>@enderror
                </div>

                <div class="event-create-grid">
                    <div class="form-group">
                        <label for="venue_id" class="form-label">Venue <span aria-hidden="true">*</span></label>
                        <select id="venue_id" name="venue_id" required class="form-select" aria-describedby="venue-help venue-error" @if ($errors->has('venue_id')) aria-invalid="true" @endif>
                            <option value="">Choose a venue</option>
                            @foreach ($venues as $venue)
                                <option value="{{ $venue->id }}" {{ old('venue_id') == $venue->id ? 'selected' : '' }}>{{ $venue->name }} — venue capacity: {{ $venue->default_capacity }}</option>
                            @endforeach
                        </select>
                        <p id="venue-help" class="event-field-help">The venue is shown to attendees and helps staff identify the event.</p>
                        @error('venue_id')<p id="venue-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group">
                        <label for="capacity" class="form-label">Maximum tickets <span aria-hidden="true">*</span></label>
                        <input id="capacity" name="capacity" type="number" value="{{ old('capacity', 50) }}" min="1" required class="form-input" aria-describedby="capacity-help capacity-error" @if ($errors->has('capacity')) aria-invalid="true" @endif>
                        <p id="capacity-help" class="event-field-help">Set the total number of tickets available.</p>
                        @error('capacity')<p id="capacity-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description <span class="event-optional">Optional</span></label>
                    <textarea id="description" name="description" rows="4" class="form-textarea" placeholder="Share event details and instructions for attendees." aria-describedby="description-help description-error" @if ($errors->has('description')) aria-invalid="true" @endif>{{ old('description') }}</textarea>
                    <p id="description-help" class="event-field-help">A short description helps attendees know what to expect.</p>
                    @error('description')<p id="description-error" class="event-field-error">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="event-create-step" data-event-step="2" aria-labelledby="event-step-schedule">
                <h2 id="event-step-schedule" tabindex="-1">Schedule</h2>
                <p class="event-create-intro">Enter local venue time. All four times use Philippine Time (PHT). For a demo, use future placeholder dates and label the event “Demo” or “Simulation.” Only use confirmed dates for real events.</p>

                <div class="event-create-grid">
                    <div class="form-group">
                        <label for="registration_opens_at" class="form-label">Registration opens <span aria-hidden="true">*</span></label>
                        <input id="registration_opens_at" type="datetime-local" name="registration_opens_at" value="{{ $openValue }}" required class="form-input" aria-describedby="registration-opens-help registration-opens-error" @if ($errors->has('registration_opens_at')) aria-invalid="true" @endif>
                        <p id="registration-opens-help" class="event-field-help">When attendees may begin registering.</p>
                        @error('registration_opens_at')<p id="registration-opens-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group">
                        <label for="registration_closes_at" class="form-label">Registration closes <span aria-hidden="true">*</span></label>
                        <input id="registration_closes_at" type="datetime-local" name="registration_closes_at" value="{{ $closeValue }}" required class="form-input" aria-describedby="registration-closes-help registration-closes-error" @if ($errors->has('registration_closes_at')) aria-invalid="true" @endif>
                        <p id="registration-closes-help" class="event-field-help">Registration must close before the event starts.</p>
                        @error('registration_closes_at')<p id="registration-closes-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group">
                        <label for="starts_at" class="form-label">Event starts <span aria-hidden="true">*</span></label>
                        <input id="starts_at" type="datetime-local" name="starts_at" value="{{ $startValue }}" required class="form-input" aria-describedby="starts-at-help starts-at-error" @if ($errors->has('starts_at')) aria-invalid="true" @endif>
                        <p id="starts-at-help" class="event-field-help">When the event program begins.</p>
                        @error('starts_at')<p id="starts-at-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group">
                        <label for="ends_at" class="form-label">Event ends <span aria-hidden="true">*</span></label>
                        <input id="ends_at" type="datetime-local" name="ends_at" value="{{ $endValue }}" required class="form-input" aria-describedby="ends-at-help ends-at-error" @if ($errors->has('ends_at')) aria-invalid="true" @endif>
                        <p id="ends-at-help" class="event-field-help">The event end must be after its start.</p>
                        @error('ends_at')<p id="ends-at-error" class="event-field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="event-field-error" data-schedule-error role="status" hidden></p>
                @if ($errors->hasAny(['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at']))
                    <p class="event-field-error" role="status">Check that registration opens before it closes, registration closes before the event starts, and the event ends after it starts.</p>
                @endif
            </section>

            <section class="event-create-step" data-event-step="3" aria-labelledby="event-step-registration">
                <h2 id="event-step-registration" tabindex="-1">Registration and admission</h2>
                <p class="event-create-intro">Choose who can register and what staff will check at the venue.</p>

                <div class="form-group">
                    <label for="registration_eligibility" class="form-label">Who can register? <span aria-hidden="true">*</span></label>
                    <select id="registration_eligibility" name="registration_eligibility" class="form-select" required aria-describedby="eligibility-help eligibility-error" @if ($errors->has('registration_eligibility')) aria-invalid="true" @endif>
                        <option value="public" {{ $eligibility === 'public' ? 'selected' : '' }}>Public — guests can check out without a Firebase account</option>
                        <option value="student" {{ $eligibility === 'student' ? 'selected' : '' }}>Students — a verified Firebase student account is required</option>
                    </select>
                    @if ($requiresInstitutionalEmail)
                        <p id="eligibility-help" class="event-field-help">The current policy requires new student accounts and student registrations to use <code>{{ '@'.$institutionalDomain }}</code>. Existing verified student accounts keep access. Email verification confirms mailbox access, not current enrollment.</p>
                    @else
                        <p id="eligibility-help" class="event-field-help">The current policy allows personal email for verified Firebase student accounts. Email verification confirms mailbox access, not current enrollment.</p>
                    @endif
                    @error('registration_eligibility')<p id="eligibility-error" class="event-field-error">{{ $message }}</p>@enderror
                </div>

                <div class="event-create-choice">
                    <input type="hidden" name="re_entry_allowed" value="0">
                    <label for="re_entry_allowed">
                        <input id="re_entry_allowed" type="checkbox" name="re_entry_allowed" value="1" {{ old('re_entry_allowed') ? 'checked' : '' }}>
                        <span><strong>Allow re-entry</strong><small>Attendees may return after leaving; staff records each exit and return.</small></span>
                    </label>
                </div>

                <div class="event-create-choice">
                    <input type="hidden" name="ticket_transfers_allowed" value="0">
                    <label for="ticket_transfers_allowed">
                        <input id="ticket_transfers_allowed" type="checkbox" name="ticket_transfers_allowed" value="1" {{ old('ticket_transfers_allowed') ? 'checked' : '' }}>
                        <span><strong>Allow ticket transfers</strong><small>Verified students may transfer an unused ticket to another verified student before the event ends. Closing registration does not close transfers.</small></span>
                    </label>
                </div>

                <div class="form-group">
                    <label for="identity_verification_level" class="form-label">What should staff check? <span aria-hidden="true">*</span></label>
                    <select id="identity_verification_level" name="identity_verification_level" class="form-select" required aria-describedby="identity-help identity-error" @if ($errors->has('identity_verification_level')) aria-invalid="true" @endif>
                        <option value="standard" {{ old('identity_verification_level', 'standard') === 'standard' ? 'selected' : '' }}>Standard details — compare the attendee name and identifier</option>
                        <option value="id_check" {{ old('identity_verification_level') === 'id_check' ? 'selected' : '' }}>Approved ID check — staff confirm they checked an ID</option>
                    </select>
                    <p id="identity-help" class="event-field-help">Student numbers are self-reported. This setting does not collect ID photos.</p>
                    @error('identity_verification_level')<p id="identity-error" class="event-field-error">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="event-create-step" data-event-step="4" aria-labelledby="event-step-review">
                <h2 id="event-step-review" tabindex="-1">Review and create</h2>
                <p class="event-create-intro">Check the details before saving. You can go back and change them.</p>

                <dl class="event-create-review">
                    <div><dt>Event</dt><dd data-event-summary="title">{{ old('title', 'Add an event name') }}</dd></div>
                    <div><dt>Venue and ticket limit</dt><dd data-event-summary="venue">{{ $selectedVenue?->name ?? 'Choose a venue' }} · {{ old('capacity', 50) }} tickets</dd></div>
                    <div><dt>Registration window (PHT)</dt><dd data-event-summary="registration">{{ str_replace('T', ' ', $openValue) }} to {{ str_replace('T', ' ', $closeValue) }}</dd></div>
                    <div><dt>Event time (PHT)</dt><dd data-event-summary="schedule">{{ str_replace('T', ' ', $startValue) }} to {{ str_replace('T', ' ', $endValue) }}</dd></div>
                    <div><dt>Who can register?</dt><dd data-event-summary="eligibility">{{ $eligibilityLabel }}</dd></div>
                    <div><dt>Re-entry</dt><dd data-event-summary="reentry">{{ old('re_entry_allowed') ? 'Allowed' : 'Not allowed' }}</dd></div>
                    <div><dt>Ticket transfers</dt><dd data-event-summary="transfers">{{ old('ticket_transfers_allowed') ? 'Allowed' : 'Not allowed' }}</dd></div>
                    <div><dt>Staff identity check</dt><dd data-event-summary="identity">{{ old('identity_verification_level', 'standard') === 'id_check' ? 'Approved ID check' : 'Standard details' }}</dd></div>
                </dl>

                <div class="event-create-note" role="note">
                    <strong>This creates a draft only.</strong>
                    <p>It will not be published or open registration. Ingress adds a free General Admission category and Main Entrance, Gate A, and Gate B. After creation, set ticket eligibility or optional simulated prices, add gates if needed, assign scanner staff, and publish when ready.</p>
                    <p>Simulated paid categories are available only when enabled by the administrator. Checkout does not charge real money or collect card details.</p>
                </div>

                <div class="event-create-actions">
                    <button type="submit" class="btn-primary">Create draft</button>
                </div>
            </section>

            <div class="event-create-navigation" data-event-navigation hidden>
                <button type="button" class="btn-secondary" data-event-back>Back</button>
                <span data-event-navigation-label>Step {{ $initialStep }} of 4</span>
                <button type="button" class="btn-primary" data-event-next>Continue</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const form = document.querySelector('[data-event-create-form]');
    if (!form) return;

    const steps = [...form.querySelectorAll('[data-event-step]')];
    const indicators = [...form.querySelectorAll('[data-step-indicator]')];
    const navigation = form.querySelector('[data-event-navigation]');
    const backButton = form.querySelector('[data-event-back]');
    const nextButton = form.querySelector('[data-event-next]');
    const status = form.querySelector('[data-event-step-status]');
    const navigationLabel = form.querySelector('[data-event-navigation-label]');
    let currentStep = Number(form.dataset.startStep) || 1;
    const field = name => form.elements.namedItem(name);
    const checkbox = name => form.querySelector(`input[type="checkbox"][name="${name}"]`);

    const setScheduleValidity = () => {
        const opens = field('registration_opens_at');
        const closes = field('registration_closes_at');
        const starts = field('starts_at');
        const ends = field('ends_at');
        let message = '';
        closes.setCustomValidity('');
        ends.setCustomValidity('');
        if (opens.value && closes.value && closes.value <= opens.value) {
            message = 'Registration must close after it opens.';
            closes.setCustomValidity(message);
        } else if (closes.value && starts.value && closes.value >= starts.value) {
            message = 'Registration must close before the event starts.';
            closes.setCustomValidity(message);
        }
        if (starts.value && ends.value && ends.value <= starts.value) {
            const endMessage = 'The event end must be after its start.';
            ends.setCustomValidity(endMessage);
            if (!message) message = endMessage;
        }
        const error = form.querySelector('[data-schedule-error]');
        error.textContent = message;
        error.hidden = message === '';
    };

    const updateSummary = () => {
        const venue = field('venue_id').selectedOptions[0];
        const text = (name, value) => {
            const target = form.querySelector(`[data-event-summary="${name}"]`);
            if (target) target.textContent = value;
        };
        const displayTime = value => value ? `${value.replace('T', ' ')} PHT` : 'Choose a time';
        text('title', field('title').value.trim() || 'Add an event name');
        text('venue', `${venue?.value ? venue.textContent.trim() : 'Choose a venue'} · ${field('capacity').value || '0'} tickets`);
        text('registration', `${displayTime(field('registration_opens_at').value)} to ${displayTime(field('registration_closes_at').value)}`);
        text('schedule', `${displayTime(field('starts_at').value)} to ${displayTime(field('ends_at').value)}`);
        text('eligibility', field('registration_eligibility').selectedOptions[0]?.textContent.trim() || 'Choose who can register');
        text('reentry', checkbox('re_entry_allowed').checked ? 'Allowed' : 'Not allowed');
        text('transfers', checkbox('ticket_transfers_allowed').checked ? 'Allowed' : 'Not allowed');
        text('identity', field('identity_verification_level').selectedOptions[0]?.textContent.trim() || 'Choose a staff check');
    };

    const showStep = (stepNumber, focusHeading = false) => {
        currentStep = Math.max(1, Math.min(steps.length, stepNumber));
        steps.forEach((step, index) => { step.hidden = index + 1 !== currentStep; });
        indicators.forEach((indicator, index) => {
            const number = index + 1;
            indicator.dataset.state = number < currentStep ? 'complete' : (number === currentStep ? 'current' : 'upcoming');
            if (number === currentStep) indicator.setAttribute('aria-current', 'step');
            else indicator.removeAttribute('aria-current');
        });
        const message = `Step ${currentStep} of ${steps.length}: ${steps[currentStep - 1].querySelector('h2').textContent}`;
        status.textContent = message;
        navigationLabel.textContent = `Step ${currentStep} of ${steps.length}`;
        backButton.disabled = currentStep === 1;
        nextButton.hidden = currentStep === steps.length;
        navigation.hidden = false;
        if (focusHeading) steps[currentStep - 1].querySelector('h2').focus();
    };

    const validateStep = step => {
        if (step === 2) setScheduleValidity();
        for (const control of steps[step - 1].querySelectorAll('input, select, textarea')) {
            if (control.type === 'hidden') continue;
            if (!control.checkValidity()) {
                control.reportValidity();
                control.focus();
                return false;
            }
        }
        return true;
    };

    form.classList.add('event-create-wizard-active');
    form.setAttribute('novalidate', 'novalidate');
    updateSummary();
    showStep(currentStep, Boolean(document.querySelector('#event-create-errors')));

    backButton.addEventListener('click', () => showStep(currentStep - 1, true));
    nextButton.addEventListener('click', () => {
        if (validateStep(currentStep)) showStep(currentStep + 1, true);
    });
    form.addEventListener('input', event => {
        if (event.target.matches('[type="datetime-local"]')) setScheduleValidity();
        updateSummary();
    });
    form.addEventListener('change', updateSummary);
    form.addEventListener('submit', event => {
        if (currentStep < steps.length) {
            event.preventDefault();
            if (validateStep(currentStep)) showStep(currentStep + 1, true);
            return;
        }
        setScheduleValidity();
        const invalid = [...form.elements].find(control => control.willValidate && !control.checkValidity());
        if (!invalid) return;
        event.preventDefault();
        const invalidStep = Number(invalid.closest('[data-event-step]')?.dataset.eventStep) || 1;
        showStep(invalidStep, true);
        invalid.reportValidity();
        invalid.focus();
    });
})();
</script>
@endsection
