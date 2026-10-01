@extends('layouts.app')

@section('content')
<div style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">Attendance: {{ $event->title }}</h1>
            <p style="color: var(--neutral-muted); font-size: 0.875rem;">Venue: {{ $event->venue->name }} &bull; Gate Admission Monitoring</p>
        </div>
        <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary">Back to Event</a>
    </div>
</div>

<div class="card" style="text-align: center; padding: 3rem; color: var(--neutral-muted);">
    <p>Live attendance metrics and real-time admission feed active.</p>
</div>
@endsection
