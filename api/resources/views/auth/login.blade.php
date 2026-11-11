@extends('layouts.app')

@section('content')
<div class="login-page">
    <section class="login-page__brand" aria-label="Ingress platform">
        <x-app-logo variant="horizontal-transparent" alt="Ingress" class="login-page__logo" />
        <x-app-logo variant="symbol-transparent" alt="" aria-hidden="true" class="login-page__watermark" />
        <div class="login-page__copy">
            <p class="login-page__description">Manage university events, registration, and attendance through one platform.</p>
        </div>
    </section>

    <section class="login-form-column" aria-labelledby="login-title">
        <h1 id="login-title" class="login-form__title">Organizer Login</h1>

        @if ($errors->any())
            <div class="form-error-summary login-form__errors" role="alert" aria-labelledby="login-errors-title">
                <h2 id="login-errors-title">Sign in could not be completed</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="login-form" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label for="email" class="form-label">University Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="form-input login-form__input" placeholder="organizer@mseuf.edu.ph">
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="login-password-field">
                    <input id="password" type="password" name="password" required autocomplete="current-password" class="form-input login-form__input" placeholder="••••••••" data-password-input>
                    <button class="login-password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password" data-password-toggle>
                        <x-icon name="eye" size="19" class="login-password-toggle__icon--show" />
                        <x-icon name="eye-off" size="19" class="login-password-toggle__icon--hide" />
                    </button>
                </div>
            </div>

            <label class="login-form__remember">
                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                <span>Remember this device</span>
            </label>

            <button type="submit" class="btn-primary login-form__submit">Sign In</button>
        </form>
    </section>
</div>
@endsection
