@props(['event', 'active' => 'overview', 'trackSections' => false])

@php
    $eventUrl = route('organizer.events.show', $event);
    $navigationItems = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'layout-dashboard', 'target' => 'event-overview'],
        ['key' => 'gates', 'label' => 'Gates', 'icon' => 'door-open', 'target' => 'event-gates'],
        ['key' => 'staff', 'label' => 'Staff', 'icon' => 'users', 'target' => 'event-staff'],
        ['key' => 'tickets', 'label' => 'Tickets', 'icon' => 'ticket', 'target' => 'event-transfers'],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'chart-no-axes-column', 'route' => route('organizer.attendance', $event)],
    ];
@endphp

<div class="event-island-sticky">
    <nav class="event-island" aria-label="Event management" data-event-island data-track-sections="{{ $trackSections ? 'true' : 'false' }}">
        @foreach ($navigationItems as $item)
            @php($isActive = $active === $item['key'])
            <a
                class="event-island__link {{ $isActive ? 'is-active' : '' }}"
                href="{{ $item['route'] ?? $eventUrl . '#' . $item['target'] }}"
                data-island-link="{{ $item['key'] }}"
                @if (isset($item['target'])) data-island-target="{{ $item['target'] }}" @endif
                @if ($isActive) aria-current="{{ $item['key'] === 'attendance' ? 'page' : 'location' }}" @endif
            >
                <x-icon :name="$item['icon']" size="18" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
