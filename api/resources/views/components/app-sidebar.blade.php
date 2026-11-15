@props(['user'])

@php
    $dashboardIsActive = request()->routeIs('organizer.dashboard');
    $eventsAreActive = request()->routeIs('organizer.events.*') || request()->routeIs('organizer.attendance');
@endphp

<aside class="app-sidebar" id="app-sidebar" aria-label="Application sidebar" aria-hidden="false" tabindex="-1" data-app-sidebar>
    <div class="app-sidebar__brand-row">
        <a class="app-sidebar__brand" href="{{ route('organizer.dashboard') }}" aria-label="Ingress Dashboard" title="Ingress Dashboard" data-sidebar-brand>
            <x-app-logo variant="horizontal" alt="" class="app-sidebar__logo app-sidebar__logo--horizontal" />
            <x-app-logo variant="symbol" alt="" class="app-sidebar__logo app-sidebar__logo--symbol" />
        </a>

        <button
            class="app-sidebar__control app-sidebar__collapse"
            type="button"
            aria-controls="app-sidebar"
            aria-expanded="true"
            aria-label="Collapse sidebar"
            title="Collapse sidebar"
            data-sidebar-collapse
        >
            <x-icon name="panel-left-close" size="18" />
        </button>

        <button
            class="app-sidebar__control app-sidebar__close"
            type="button"
            aria-label="Close navigation"
            title="Close navigation"
            data-sidebar-close
        >
            <x-icon name="x" size="18" />
        </button>
    </div>

    <nav class="app-sidebar__nav" aria-label="Primary navigation">
        <x-sidebar-nav-item
            :href="route('organizer.dashboard')"
            icon="layout-dashboard"
            label="Dashboard"
            :active="$dashboardIsActive"
        />
        <x-sidebar-nav-item
            :href="route('organizer.events.index')"
            icon="calendar-days"
            label="Events"
            :active="$eventsAreActive"
        />
        @if ($user->isAdmin() && config('ingress.mode') === 'cloud')
            <x-sidebar-nav-item
                :href="route('organizer.venues.index')"
                icon="map-pin"
                label="Venues"
                :active="request()->routeIs('organizer.venues.*')"
            />
            <x-sidebar-nav-item
                :href="route('admin.settings.student-access')"
                icon="shield-check"
                label="Student Access"
                :active="request()->routeIs('admin.settings.*')"
            />
        @endif
    </nav>

    <div class="app-sidebar__footer">
        <div class="app-sidebar__user">
            <span class="app-sidebar__user-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
            <span class="app-sidebar__user-copy">
                <span class="app-sidebar__user-name">{{ $user->name }}</span>
                <span class="app-sidebar__user-role">{{ ucfirst($user->role) }}</span>
            </span>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="app-sidebar__logout" type="submit" aria-label="Sign out" title="Sign out">
                <x-icon name="log-out" size="18" />
                <span class="app-sidebar__logout-label">Sign Out</span>
            </button>
        </form>
    </div>
</aside>
