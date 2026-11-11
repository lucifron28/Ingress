@extends('layouts.app')

@section('content')
<div class="login-card">
    <div class="login-card__header">
        <x-app-logo variant="horizontal" alt="Ingress" class="login-card__logo" />
        <h1 class="login-card__title">Organizer portal</h1>
        <p class="login-card__description">Event Management &amp; Attendance Administration</p>
    </div>

    @if ($errors->any())
        <div class="form-error-summary" role="alert" aria-labelledby="login-errors-title">
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
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus class="form-input" placeholder="organizer@mseuf.edu.ph">
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password" required class="form-input" placeholder="••••••••">
        </div>

        <div>
            <label class="login-card__remember">
                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                Remember this device
            </label>
        </div>

        <button type="submit" class="btn-primary login-card__submit">
            Sign In to Dashboard
        </button>
    </form>

    <div class="login-card__note">
        <p>Students and Event Staff should use the Ingress Mobile App.</p>
        @if (config('app.demo_mode') && ! app()->environment('production'))
            <p class="login-card__demo">Default Demo Organizer: <code>organizer@mseuf.edu.ph</code> / <code>password</code></p>
        @endif
    </div>
</div>
@endsection
