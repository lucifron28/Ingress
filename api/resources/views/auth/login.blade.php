@extends('layouts.app')

@section('content')
<div class="login-card">
    <div class="login-card__header">
        <x-app-logo variant="horizontal" alt="Ingress" class="login-card__logo" />
        <h1 class="login-card__title">Organizer portal</h1>
        <p style="font-size: 0.875rem; color: var(--neutral-muted);">Event Management &amp; Attendance Administration</p>
    </div>

    @if ($errors->any())
        <div style="background-color: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 4px; font-size: 0.875rem; margin-bottom: 1.5rem;">
            <ul style="padding-left: 1.25rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="form-group">
            <label for="email" class="form-label">University Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus class="form-input" placeholder="organizer@mseuf.edu.ph">
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input id="password" type="password" name="password" required class="form-input" placeholder="••••••••">
        </div>

        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: var(--neutral-body); cursor: pointer;">
                <input type="checkbox" name="remember" style="accent-color: var(--mseuf-maroon);">
                Remember this device
            </label>
        </div>

        <button type="submit" class="btn-primary" style="width: 100%; justify-content: center; padding: 0.65rem 1rem; font-size: 0.95rem;">
            Sign In to Dashboard
        </button>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--neutral-border); font-size: 0.75rem; color: var(--neutral-muted); text-align: center;">
        <p>Students and Event Staff should use the Ingress Mobile App.</p>
        @if (config('app.demo_mode'))
            <p style="margin-top: 0.35rem;">Default Demo Organizer: <code style="color: var(--mseuf-maroon); font-weight: 600;">organizer@mseuf.edu.ph</code> / <code style="color: var(--mseuf-maroon);">password</code></p>
        @endif
    </div>
</div>
@endsection
