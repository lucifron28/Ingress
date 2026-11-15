@props(['event'])

@php
    $startsAt = \App\Support\EventSchedule::local($event->starts_at);
    $endsAt = \App\Support\EventSchedule::local($event->ends_at);
@endphp

<div class="dashboard-schedule">
    <time datetime="{{ $startsAt->toIso8601String() }}">
        {{ $startsAt->format('M j, Y · g:i A') }} PHT
    </time>
    <span class="dashboard-schedule__separator">to</span>
    <time datetime="{{ $endsAt->toIso8601String() }}">
        {{ $endsAt->format('M j, Y · g:i A') }} PHT
    </time>
</div>
