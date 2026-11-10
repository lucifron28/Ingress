<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Ingress' }} - MSEUF Event Management</title>
    <link rel="icon" type="image/png" href="{{ asset('images/ingress-favicon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    @auth
        <x-app-header :user="auth()->user()" />
    @endauth

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
</body>
</html>
