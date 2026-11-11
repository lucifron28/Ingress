@extends('layouts.app')

@section('content')
<div class="login-page">
    <section class="login-page__brand" aria-label="Ingress platform">
        <x-app-logo variant="horizontal-light" alt="Ingress" class="login-page__logo" />
        <p class="login-page__description">Manage university events, registration, and attendance through one platform.</p>
    </section>

    <section class="login-card" aria-labelledby="login-title">
        <header class="login-card__header">
            <h1 id="login-title" class="login-card__title">Organizer Login</h1>
        </header>

        @if ($errors->any())
            <div class="login-card__errors" role="alert" aria-labelledby="login-errors-title">
                <h2 id="login-errors-title">Sign in could not be completed</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="login-card__form" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label for="email" class="form-label">University Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="form-input" placeholder="organizer@mseuf.edu.ph">
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="login-password-field">
                    <input id="password" type="password" name="password" required autocomplete="current-password" class="form-input" placeholder="••••••••" data-password-input>
                    <button class="login-password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle>
                        <x-icon name="eye" size="19" class="login-password-toggle__icon--show" />
                        <x-icon name="eye-off" size="19" class="login-password-toggle__icon--hide" />
                    </button>
                </div>
            </div>

            <label class="login-card__remember">
                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                <span>Remember this device</span>
            </label>

            <button type="submit" class="btn-primary login-card__submit">Sign In</button>
        </form>

        @if (config('app.demo_mode') && ! app()->environment('production'))
            <p class="login-card__demo">Demo organizer: <code>organizer@mseuf.edu.ph</code> / <code>password</code></p>
        @endif
    </section>
</div>
@endsection
