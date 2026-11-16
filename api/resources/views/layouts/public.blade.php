<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Ingress events' }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ingress-favicon.png') }}">
    @vite('resources/css/app.css')
</head>
<body class="app-body public-attendee">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <header class="public-header">
        <div class="public-header__inner">
            <a class="public-brand" href="{{ route('public.events.index') }}" aria-label="Ingress events home">
                <x-app-logo variant="horizontal" alt="Ingress" class="public-brand__logo" />
            </a>

            <nav class="public-nav" aria-label="Main navigation">
                <a href="{{ route('public.events.index') }}" @if (request()->routeIs('public.events.*')) aria-current="page" @endif>Events</a>
                @auth
                    @if (auth()->user()->isStudent())
                        <a href="{{ route('student.dashboard') }}">My tickets</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="public-nav__action" type="submit">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('student.auth') }}">Student sign in</a>
                @endauth
            </nav>

            <details class="public-mobile-menu">
                <summary class="public-mobile-menu__toggle">
                    <x-icon name="menu" size="18" />
                    <span>Menu</span>
                </summary>
                <nav class="public-mobile-menu__panel" aria-label="Mobile navigation">
                    <a href="{{ route('public.events.index') }}" @if (request()->routeIs('public.events.*')) aria-current="page" @endif>Events</a>
                    @auth
                        @if (auth()->user()->isStudent())
                            <a href="{{ route('student.dashboard') }}">My tickets</a>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="public-mobile-menu__action" type="submit">Sign out</button>
                        </form>
                    @else
                        <a href="{{ route('student.auth') }}">Student sign in</a>
                    @endauth
                </nav>
            </details>
        </div>
    </header>

    <main id="main-content" class="public-main">
        <div class="public-container">
            @if (session('success'))
                <div class="flash-message flash-message--success" role="status">{{ session('success') }}</div>
            @endif
            @if (session('info'))
                <div class="flash-message flash-message--success" role="status">{{ session('info') }}</div>
            @endif
            @yield('content')
        </div>
    </main>

    <footer class="public-footer app-footer">
        <div class="public-container app-footer__inner">
            <span>Manuel S. Enverga University Foundation</span>
            <span>Ingress Event Registration</span>
        </div>
    </footer>
</body>
</html>
