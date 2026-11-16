@props(['event'])

@php
    $gateCount = $event->accessPoints->count();
    $staffCount = $event->staffAssignments->count();
@endphp

<ul class="operations-list">
    <li>
        <x-icon name="settings-2" size="16" />
        <span>{{ $gateCount }} {{ $gateCount === 1 ? 'gate' : 'gates' }}</span>
    </li>
    <li>
        <x-icon name="users" size="16" />
        <span>{{ $staffCount }} {{ $staffCount === 1 ? 'staff member' : 'staff members' }}</span>
    </li>
</ul>
