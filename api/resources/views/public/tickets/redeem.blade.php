@extends('layouts.public')

@section('content')
    <section class="card">
        <p class="eyebrow">Transfer a guest ticket</p>
        <h1>Redeem a private transfer code</h1>
        <p>Enter the code shared by the current ticket holder. A successful transfer replaces both the admission QR and the transfer code.</p>
        <p>Possession of a redemption code grants ticket transfer control. It does not verify the redeemer's identity.</p>
        <form method="POST" action="{{ route('public.tickets.redeem.store') }}" autocomplete="off">
            @csrf
            <label for="guest-name-input">Your name</label>
            <input id="guest-name-input" name="guest_name" type="text" autocomplete="name" maxlength="255" required class="form-input">
            @error('guest_name')
                <p role="alert" class="notice">{{ $message }}</p>
            @enderror
            <label for="guest-email-input">Your contact email</label>
            <input id="guest-email-input" name="guest_email" type="email" autocomplete="email" maxlength="255" required class="form-input">
            @error('guest_email')
                <p role="alert" class="notice">{{ $message }}</p>
            @enderror
            <label for="redemption-code-input">Private redemption code</label>
            <input id="redemption-code-input" name="redemption_code" type="password" inputmode="text" autocomplete="new-password" required minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" class="form-input">
            @error('redemption_code')
                <p role="alert" class="notice">{{ $message }}</p>
            @enderror
            <button type="submit" class="button">Transfer ticket to me</button>
        </form>
    </section>
@endsection
