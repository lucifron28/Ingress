@props(['event'])

<div class="dashboard-schedule">
    <time datetime="{{ $event->starts_at->toIso8601String() }}">
        {{ $event->starts_at->format('M j, Y · g:i A') }}
    </time>
    <span class="dashboard-schedule__separator">to</span>
    <time datetime="{{ $event->ends_at->toIso8601String() }}">
        {{ $event->ends_at->format('M j, Y · g:i A') }}
    </time>
</div>
