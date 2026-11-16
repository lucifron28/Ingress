@extends('layouts.public')

@section('content')
    <nav class="public-breadcrumbs" aria-label="Breadcrumb">
        <a href="{{ route('public.events.index') }}">Campus events</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Transfer a guest ticket</span>
    </nav>

    <section class="public-panel public-transfer-form" aria-labelledby="transfer-ticket-title">
        <header class="public-page-header public-page-header--compact">
            <p class="public-eyebrow">Guest ticket transfer</p>
            <h1 id="transfer-ticket-title" class="public-page-title">Redeem a private transfer code</h1>
            <p class="public-page-lede">Enter the code shared by the current ticket holder. A successful transfer replaces both the admission QR and the transfer code.</p>
        </header>

        <div class="public-notice" role="note">
            Possession of a redemption code grants ticket transfer control. It does not verify the redeemer's identity.
        </div>

        @if ($errors->any())
            <div class="form-error-summary" role="alert" tabindex="-1" aria-labelledby="transfer-errors-title">
                <h2 id="transfer-errors-title">Please review the information below</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="public-form" method="POST" action="{{ route('public.tickets.redeem.store') }}" autocomplete="off">
            @csrf
            <div class="form-group">
                <label class="form-label" for="guest-name-input">Your name <span class="required-indicator" aria-hidden="true">*</span></label>
                <input id="guest-name-input" name="guest_name" type="text" autocomplete="name" maxlength="255" required class="form-input" @if ($errors->has('guest_name')) aria-invalid="true" aria-describedby="guest-name-error" @endif>
                @error('guest_name')
                    <p id="guest-name-error" role="alert" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="guest-email-input">Your contact email <span class="required-indicator" aria-hidden="true">*</span></label>
                <input id="guest-email-input" name="guest_email" type="email" autocomplete="email" maxlength="255" required class="form-input" @if ($errors->has('guest_email')) aria-invalid="true" aria-describedby="guest-email-error" @endif>
                @error('guest_email')
                    <p id="guest-email-error" role="alert" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="redemption-code-input">Private redemption code <span class="required-indicator" aria-hidden="true">*</span></label>
                <input id="redemption-code-input" name="redemption_code" type="password" inputmode="text" autocomplete="new-password" required minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" class="form-input" @if ($errors->has('redemption_code')) aria-invalid="true" aria-describedby="redemption-code-error" @endif>
                @error('redemption_code')
                    <p id="redemption-code-error" role="alert" class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.button type="submit"><span>Transfer ticket to me</span></x-ui.button>
        </form>
    </section>
@endsection
