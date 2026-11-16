<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Ingress' }} - MSEUF Event Management</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ingress-gateway-symbol.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/ingress-app-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    @php($authenticatedUser = auth()->user())

    <div class="app-shell {{ $authenticatedUser ? '' : 'app-shell--guest' }} {{ request()->routeIs('login') ? 'app-shell--login' : '' }}" data-app-shell>
        @if ($authenticatedUser)
            <x-app-sidebar :user="$authenticatedUser" />
            <div class="app-sidebar-backdrop" data-sidebar-backdrop hidden aria-hidden="true"></div>
        @endif

        <div class="app-main-area" @if ($authenticatedUser) data-app-main-area @endif>
            @if ($authenticatedUser)
                <header class="app-mobile-header">
                    <button
                        class="app-mobile-header__menu"
                        type="button"
                        aria-controls="app-sidebar"
                        aria-expanded="false"
                        aria-label="Open navigation"
                        title="Open navigation"
                        data-sidebar-open
                    >
                        <x-icon name="menu" size="20" />
                    </button>
                    <a class="app-mobile-header__brand" href="{{ route('organizer.dashboard') }}">
                        <x-app-logo variant="horizontal" alt="Ingress Dashboard" class="app-mobile-header__logo" />
                    </a>
                </header>
            @endif

            <main id="main-content" class="app-main">
                <div class="app-container">
                    @if (session('success'))
                        <div class="flash-message flash-message--success" role="status">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="flash-message flash-message--error" role="alert">
                            {{ session('error') }}
                        </div>
                    @endif

                    {{ $slot ?? '' }}
                    @yield('content')
                </div>
            </main>

            <footer class="app-footer">
                <div class="app-container app-footer__inner">
                    <span>Manuel S. Enverga University Foundation</span>
                    <span>Ingress Event Management</span>
                </div>
            </footer>
        </div>
    </div>
</body>
</html>
