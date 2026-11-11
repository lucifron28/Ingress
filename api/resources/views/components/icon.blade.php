@props(['name', 'size' => 20, 'class' => ''])

<svg
    class="icon {{ $class }}"
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
>
    @switch($name)
        @case('layout-dashboard')
            <rect x="3" y="3" width="7" height="9" rx="1" />
            <rect x="14" y="3" width="7" height="5" rx="1" />
            <rect x="14" y="12" width="7" height="9" rx="1" />
            <rect x="3" y="16" width="7" height="5" rx="1" />
            @break
        @case('calendar-days')
            <path d="M8 2v4M16 2v4" />
            <rect x="3" y="4" width="18" height="18" rx="2" />
            <path d="M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('settings-2')
            <path d="M20 7h-9M14 17H5" />
            <circle cx="17" cy="17" r="3" />
            <circle cx="7" cy="7" r="3" />
            @break
        @case('chart-no-axes-column')
            <path d="M5 20v-6M12 20V4M19 20V9" />
            @break
        @case('pencil')
            <path d="m16 5 3 3L8 19l-4 1 1-4Z" />
            <path d="m14 7 3 3" />
            @break
        @case('log-out')
            <path d="m16 17 5-5-5-5M21 12H9" />
            <path d="M12 19H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h7" />
            @break
        @case('check-circle-2')
            <circle cx="12" cy="12" r="10" />
            <path d="m9 12 2 2 4-4" />
            @break
        @case('file-pen-line')
            <path d="M12 22h-7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8l6 6v2" />
            <path d="M13 3v6h6M8 13h4M8 17h3" />
            <path d="m16 19 2 2 4-4-2-2-4 4Z" />
            @break
        @case('x-circle')
            <circle cx="12" cy="12" r="10" />
            <path d="m15 9-6 6M9 9l6 6" />
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
            <circle cx="10" cy="7" r="4" />
            <path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
            @break
        @case('arrow-up-right')
            <path d="M7 7h10v10M7 17 17 7" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('x')
            <path d="m18 6-12 12M6 6l12 12" />
            @break
        @case('map-pin')
            <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
            <circle cx="12" cy="10" r="2.5" />
            @break
        @case('clock-3')
            <circle cx="12" cy="12" r="10" />
            <path d="M12 6v6l4 2" />
            @break
        @case('chevron-left')
            <path d="m15 18-6-6 6-6" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('door-open')
            <path d="M13 4h3a2 2 0 0 1 2 2v14" />
            <path d="M2 20h3M13 20h9" />
            <path d="M5 20V5a2 2 0 0 1 2-2h8v17" />
            <path d="M9 12h.01" />
            @break
        @case('ticket')
            <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z" />
            <path d="M13 5v2M13 11v2M13 17v2" />
            @break
        @case('eye')
            <path d="M2.06 12C3.42 7.72 7.36 5 12 5s8.58 2.72 9.94 7c-1.36 4.28-5.3 7-9.94 7s-8.58-2.72-9.94-7Z" />
            <circle cx="12" cy="12" r="3" />
            @break
        @case('download')
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <path d="m7 10 5 5 5-5M12 15V3" />
            @break
        @case('filter')
            <path d="M22 3H2l8 9.5V20l4 2v-9.5L22 3Z" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
            @break
        @case('refresh-cw')
            <path d="M20 11a8 8 0 0 0-14.93-3M4 13a8 8 0 0 0 14.93 3" />
            <path d="M5 3v5h5M19 21v-5h-5" />
            @break
    @endswitch
</svg>
