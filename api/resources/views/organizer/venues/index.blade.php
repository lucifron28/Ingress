@extends('layouts.app')

@section('content')
<div style="margin-bottom: 1.5rem;">
    <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--neutral-dark);">Venue management</h1>
    <p style="color: var(--neutral-muted);">Add campus venues and update their details. Event capacity remains the registration limit; a venue's default capacity is a starting value.</p>
</div>

@if ($errors->any())
    <div role="alert" style="background: #fef2f2; color: #991b1b; padding: 1rem; margin-bottom: 1.25rem;">
        <ul style="padding-left: 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="card" style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem;">Add a venue</h2>
    <form method="POST" action="{{ route('organizer.venues.store') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: .75rem; align-items: end;">
        @csrf
        <label>Name
            <input class="form-input" name="name" maxlength="255" required value="{{ old('name') }}">
        </label>
        <label>Venue code
            <input class="form-input" name="code" maxlength="32" pattern="[A-Za-z0-9_-]+" required value="{{ old('code') }}" aria-describedby="venue-code-help">
        </label>
        <label>Location
            <input class="form-input" name="location" maxlength="255" value="{{ old('location') }}">
        </label>
        <label>Default capacity
            <input class="form-input" name="default_capacity" type="number" min="1" required value="{{ old('default_capacity', 500) }}">
        </label>
        <button class="btn-primary" type="submit">Add venue</button>
    </form>
    <p id="venue-code-help" style="color: var(--neutral-muted); font-size: .8rem; margin-top: .5rem;">Use a short code with letters, numbers, hyphens, or underscores. Codes are stored in uppercase.</p>
</section>

<section class="card">
    <h2 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem;">Venues</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Name and location</th><th>Code</th><th>Default capacity</th><th>Events</th><th>Update</th></tr>
            </thead>
            <tbody>
                @forelse ($venues as $venue)
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('organizer.venues.update', $venue) }}" id="venue-{{ $venue->id }}" style="display: grid; gap: .5rem;">
                                @csrf
                                @method('PUT')
                                <label for="venue-name-{{ $venue->id }}" style="display: grid; gap: .25rem;">Venue name
                                    <input id="venue-name-{{ $venue->id }}" class="form-input" name="name" maxlength="255" required value="{{ $venue->name }}">
                                </label>
                                <label for="venue-location-{{ $venue->id }}" style="display: grid; gap: .25rem;">Location
                                    <input id="venue-location-{{ $venue->id }}" class="form-input" name="location" maxlength="255" value="{{ $venue->location }}" placeholder="Location">
                                </label>
                                <input type="hidden" name="code" value="{{ $venue->code }}">
                                <input type="hidden" name="default_capacity" value="{{ $venue->default_capacity }}">
                            </form>
                        </td>
                        <td><code>{{ $venue->code }}</code></td>
                        <td>
                            <input id="venue-capacity-{{ $venue->id }}" class="form-input" type="number" name="default_capacity" min="1" required value="{{ $venue->default_capacity }}" form="venue-{{ $venue->id }}" aria-label="Default capacity">
                        </td>
                        <td>{{ $venue->events_count }}</td>
                        <td>
                            <button class="btn-secondary" type="submit" form="venue-{{ $venue->id }}">Save</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No venues have been added.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
