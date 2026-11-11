@php
    $event = $event ?? null;
    $opensAt = $event?->registration_opens_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i');
    $closesAt = $event?->registration_closes_at?->format('Y-m-d\TH:i') ?? now()->addDays(7)->format('Y-m-d\TH:i');
    $startsAt = $event?->starts_at?->format('Y-m-d\TH:i') ?? now()->addDays(2)->setHour(8)->setMinute(0)->format('Y-m-d\TH:i');
    $endsAt = $event?->ends_at?->format('Y-m-d\TH:i') ?? now()->addDays(2)->setHour(17)->setMinute(0)->format('Y-m-d\TH:i');
@endphp

<div class="event-editor">
    <section class="event-form-section" aria-labelledby="event-form-basic-title">
        <header class="event-form-section__header">
            <x-icon name="file-text" size="20" class="event-form-section__icon" />
            <div>
                <h2 id="event-form-basic-title" class="event-form-section__title">Basic Information</h2>
                <p class="event-form-section__description">Add the event name, venue, attendee limit, and overview.</p>
            </div>
        </header>
        <div class="form-group">
            <label for="title" class="form-label">Event Title <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
            <input id="title" type="text" name="title" value="{{ old('title', $event?->title) }}" required class="form-input" placeholder="e.g. MSEUF Computer Science Summit 2026">
        </div>

        <div class="event-form-grid">
            <div class="form-group">
                <label for="venue_id" class="form-label">Campus Venue <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <select id="venue_id" name="venue_id" required class="form-select">
                    <option value="">Select a campus venue</option>
                    @foreach ($venues as $venue)
                        <option value="{{ $venue->id }}" {{ old('venue_id', $event?->venue_id) == $venue->id ? 'selected' : '' }}>
                            {{ $venue->name }} (Capacity: {{ $venue->default_capacity }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="capacity" class="form-label">Event Capacity <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <input id="capacity" type="number" name="capacity" value="{{ old('capacity', $event?->capacity ?? 50) }}" min="1" required class="form-input">
            </div>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Description</label>
            <textarea id="description" name="description" rows="5" class="form-textarea" placeholder="Share event details, objectives, and attendee instructions...">{{ old('description', $event?->description) }}</textarea>
            <p class="form-help">This description appears in the event details.</p>
        </div>
    </section>

    <section class="event-form-section" aria-labelledby="event-form-schedule-title">
        <header class="event-form-section__header">
            <x-icon name="calendar-days" size="20" class="event-form-section__icon" />
            <div>
                <h2 id="event-form-schedule-title" class="event-form-section__title">Event Schedule</h2>
                <p class="event-form-section__description">Set when the event begins and ends.</p>
            </div>
        </header>
        <div class="event-form-grid">
            <div class="form-group">
                <label for="starts_at" class="form-label">Event Starts <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <input id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', $startsAt) }}" required class="form-input">
            </div>

            <div class="form-group">
                <label for="ends_at" class="form-label">Event Ends <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <input id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', $endsAt) }}" required class="form-input">
            </div>
        </div>
    </section>

    <section class="event-form-section" aria-labelledby="event-form-registration-title">
        <header class="event-form-section__header">
            <x-icon name="calendar-clock" size="20" class="event-form-section__icon" />
            <div>
                <h2 id="event-form-registration-title" class="event-form-section__title">Registration Settings</h2>
                <p class="event-form-section__description">Choose when attendees can register.</p>
            </div>
        </header>
        <div class="event-form-grid">
            <div class="form-group">
                <label for="registration_opens_at" class="form-label">Registration Opens <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <input id="registration_opens_at" type="datetime-local" name="registration_opens_at" value="{{ old('registration_opens_at', $opensAt) }}" required class="form-input">
            </div>

            <div class="form-group">
                <label for="registration_closes_at" class="form-label">Registration Closes <span class="required-indicator" aria-hidden="true">*</span><span class="visually-hidden">required</span></label>
                <input id="registration_closes_at" type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $closesAt) }}" required class="form-input">
            </div>
        </div>
    </section>

    <section class="event-form-section" aria-labelledby="event-form-policies-title">
        <header class="event-form-section__header">
            <x-icon name="shield-check" size="20" class="event-form-section__icon" />
            <div>
                <h2 id="event-form-policies-title" class="event-form-section__title">Admission Policies</h2>
                <p class="event-form-section__description">Set ticket transfer, re-entry, and identity check options.</p>
            </div>
        </header>
        <div class="event-policy-options">
            <input type="hidden" name="re_entry_allowed" value="0">
            <label class="event-policy-option" for="re_entry_allowed">
                <input id="re_entry_allowed" type="checkbox" name="re_entry_allowed" value="1" {{ old('re_entry_allowed', $event?->re_entry_allowed ?? false) ? 'checked' : '' }}>
                <span>
                    <strong>Allow re-entry</strong>
                    <span>Attendees who exit may enter again after staff confirms each movement.</span>
                </span>
            </label>

            <input type="hidden" name="ticket_transfers_allowed" value="0">
            <label class="event-policy-option" for="ticket_transfers_allowed">
                <input id="ticket_transfers_allowed" type="checkbox" name="ticket_transfers_allowed" value="1" {{ old('ticket_transfers_allowed', $event?->ticket_transfers_allowed ?? false) ? 'checked' : '' }}>
                <span>
                    <strong>Allow ticket transfers</strong>
                    <span>Verified students may transfer an unadmitted ticket before the event snapshot is prepared.</span>
                </span>
            </label>
        </div>

        <div class="form-group event-identity-policy">
            <label for="identity_verification_level" class="form-label">Identity Verification</label>
            <select id="identity_verification_level" name="identity_verification_level" class="form-select" required>
                <option value="standard" {{ old('identity_verification_level', $event?->identity_verification_level ?? 'standard') === 'standard' ? 'selected' : '' }}>Standard — compare the attendee name and identifier</option>
                <option value="id_check" {{ old('identity_verification_level', $event?->identity_verification_level) === 'id_check' ? 'selected' : '' }}>Approved ID — require staff to confirm an ID check</option>
            </select>
            <p class="form-help">A student number is self-reported unless separately verified. Photo collection is unavailable until an approved access and retention policy exists.</p>
        </div>
    </section>

    <div class="event-form-actions">
        <a href="{{ $cancelUrl }}" class="btn-secondary">Cancel</a>
        <button type="submit" class="btn-primary">{{ $submitLabel }}</button>
    </div>
</div>
