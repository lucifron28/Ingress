@props(['user'])

@php
    $dashboardIsActive = request()->routeIs('organizer.dashboard');
    $eventsAreActive = request()->routeIs('organizer.events.*') || request()->routeIs('organizer.attendance');
@endphp

<header class="app-header">
    <div class="app-container app-header__inner">
        <a class="app-header__brand" href="{{ route('organizer.dashboard') }}" aria-label="Ingress dashboard">
            <span class="app-header__brand-mark">
                <img src="{{ asset('images/ingress-mark.png') }}" alt="" width="24" height="24">
            </span>
            <span>Ingress</span>
        </a>

        <button
            class="app-header__menu-toggle"
            type="button"
            aria-controls="app-navigation"
            aria-expanded="false"
            aria-label="Open navigation"
            data-nav-toggle
        >
            <x-icon name="menu" size="18" class="app-header__icon-open" />
            <x-icon name="x" size="18" class="app-header__icon-close" />
            <span data-nav-toggle-label>Menu</span>
        </button>

        <div id="app-navigation" class="app-header__menu" data-nav-menu data-open="true">
            <nav class="app-header__links" aria-label="Primary navigation">
                <a
                    class="app-header__link {{ $dashboardIsActive ? 'is-active' : '' }}"
                    href="{{ route('organizer.dashboard') }}"
                    @if ($dashboardIsActive) aria-current="page" @endif
                >
                    <x-icon name="layout-dashboard" size="18" />
                    <span>Dashboard</span>
                </a>
                <a
                    class="app-header__link {{ $eventsAreActive ? 'is-active' : '' }}"
                    href="{{ route('organizer.events.index') }}"
                    @if ($eventsAreActive) aria-current="page" @endif
                >
                    <x-icon name="calendar-days" size="18" />
                    <span>Events</span>
                </a>
            </nav>

            <div class="app-header__account">
                <div class="app-header__identity">
                    <span class="app-header__user-name">{{ $user->name }}</span>
                    <span class="app-header__user-role">{{ ucfirst($user->role) }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.button variant="header" type="submit">
                        <x-icon name="log-out" size="16" />
                        <span>Sign Out</span>
                    </x-ui.button>
                </form>
            </div>
        </div>
    </div>
</header>
