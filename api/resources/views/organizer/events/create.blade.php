@extends('layouts.app')

@section('content')
<div style="max-width: 760px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--neutral-dark);">Create New Campus Event</h1>
            <p style="color: var(--neutral-muted); font-size: 0.875rem;">Set up event schedule, capacity, and admission access points</p>
        </div>
        <a href="{{ route('organizer.events.index') }}" class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 4px; font-size: 0.875rem; margin-bottom: 1.5rem;">
            <ul style="padding-left: 1.25rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <form method="POST" action="{{ route('organizer.events.store') }}">
            @csrf

            <div class="form-group">
                <label for="title" class="form-label">Event Title *</label>
                <input id="title" type="text" name="title" value="{{ old('title') }}" required class="form-input" placeholder="e.g. MSEUF Computer Science Summit 2026">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="venue_id" class="form-label">Campus Venue *</label>
                    <select id="venue_id" name="venue_id" required class="form-select">
                        <option value="">-- Select Campus Venue --</option>
                        @foreach ($venues as $venue)
                            <option value="{{ $venue->id }}" {{ old('venue_id') == $venue->id ? 'selected' : '' }}>
                                {{ $venue->name }} (Capacity: {{ $venue->default_capacity }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="capacity" class="form-label">Event Capacity (Seats) *</label>
                    <input id="capacity" type="number" name="capacity" value="{{ old('capacity', 50) }}" min="1" required class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="4" class="form-textarea" placeholder="Provide event details, objectives, and instructions for attendees...">{{ old('description') }}</textarea>
            </div>

            <div class="form-group">
                <label for="registration_eligibility" class="form-label">Who may register?</label>
                <select id="registration_eligibility" name="registration_eligibility" class="form-select" required>
                    <option value="public" {{ old('registration_eligibility', 'public') === 'public' ? 'selected' : '' }}>Public — guests may check out without an account</option>
                    <option value="student" {{ old('registration_eligibility') === 'student' ? 'selected' : '' }}>Student accounts — verified Firebase identity required</option>
                </select>
                <p style="color: var(--neutral-muted); font-size: 0.8rem; margin-top: 0.4rem;">The current administrator email policy also applies to student-only event and ticket categories. Email verification confirms mailbox access, not university enrollment. Every new event starts with a free General Admission category. Additional categories can be added after creation.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="registration_opens_at" class="form-label">Registration Opens *</label>
                    <input id="registration_opens_at" type="datetime-local" name="registration_opens_at" value="{{ old('registration_opens_at', now()->format('Y-m-d\TH:i')) }}" required class="form-input">
                </div>

                <div class="form-group">
                    <label for="registration_closes_at" class="form-label">Registration Closes *</label>
                    <input id="registration_closes_at" type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', now()->addDay()->format('Y-m-d\TH:i')) }}" required class="form-input">
                    <p style="margin-top: .25rem; color: var(--neutral-muted); font-size: .875rem;">Registration closes before the event starts.</p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="starts_at" class="form-label">Event Starts *</label>
                    <input id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', now()->addDays(2)->setHour(8)->setMinute(0)->format('Y-m-d\TH:i')) }}" required class="form-input">
                </div>

                <div class="form-group">
                    <label for="ends_at" class="form-label">Event Ends *</label>
                    <input id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', now()->addDays(2)->setHour(17)->setMinute(0)->format('Y-m-d\TH:i')) }}" required class="form-input">
                </div>
            </div>

            <div class="form-group" style="margin-top: 0.5rem;">
                <input type="hidden" name="re_entry_allowed" value="0">
                <label style="display: flex; align-items: flex-start; gap: 0.5rem;">
                    <input type="checkbox" name="re_entry_allowed" value="1" {{ old('re_entry_allowed') ? 'checked' : '' }}>
                    <span>
                        <strong>Allow re-entry</strong>
                        <span style="display: block; color: var(--neutral-muted); font-size: 0.8rem;">Attendees who exit may enter again after staff confirms each movement.</span>
                    </span>
                </label>
            </div>

            <div class="form-group" style="margin-top: 0.5rem;">
                <input type="hidden" name="ticket_transfers_allowed" value="0">
                <label style="display: flex; align-items: flex-start; gap: 0.5rem;">
                    <input type="checkbox" name="ticket_transfers_allowed" value="1" {{ old('ticket_transfers_allowed') ? 'checked' : '' }}>
                    <span>
                        <strong>Allow ticket transfers</strong>
                        <span style="display: block; color: var(--neutral-muted); font-size: 0.8rem;">Verified students can transfer an unadmitted ticket to another verified student before the event ends and before the admission snapshot is prepared. Closing registration does not close transfers.</span>
                    </span>
                </label>
            </div>

            <div class="form-group" style="margin-top: 0.5rem;">
                <label for="identity_verification_level" class="form-label">Attendee identity check</label>
                <select id="identity_verification_level" name="identity_verification_level" class="form-select" required>
                    <option value="standard" {{ old('identity_verification_level', 'standard') === 'standard' ? 'selected' : '' }}>Standard — compare the attendee name and identifier</option>
                    <option value="id_check" {{ old('identity_verification_level') === 'id_check' ? 'selected' : '' }}>Approved ID — require staff to confirm an ID check</option>
                </select>
                <p style="color: var(--neutral-muted); font-size: 0.8rem; margin-top: 0.4rem;">A student number is self-reported unless separately verified. Photo collection is unavailable until an approved access and retention policy exists.</p>
            </div>

            <div style="border-top: 1px solid var(--neutral-border); padding-top: 1.25rem; margin-top: 1rem; display: flex; justify-content: flex-end; gap: 0.75rem;">
                <a href="{{ route('organizer.events.index') }}" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">Create Event (Draft)</button>
            </div>
        </form>
    </div>
</div>
@endsection
