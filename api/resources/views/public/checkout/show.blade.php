@extends('layouts.public')

@section('content')
    <p class="eyebrow"><a href="{{ route('public.events.show', $checkoutOrder->event->slug) }}">{{ $checkoutOrder->event->title }}</a> / Simulated checkout</p>
    <section class="card stack" style="max-width: 42rem; margin: 0 auto;">
        <h1>Complete checkout</h1>
        <div class="notice" role="note">
            <strong>SIMULATED PAYMENT — NO REAL MONEY IS CHARGED.</strong>
            This demo uses test wallet and card labels only. Do not enter card numbers, security codes, PINs, or one-time codes.
        </div>

        <dl>
            <dt>Ticket</dt><dd>{{ $checkoutOrder->ticketCategory->name }}</dd>
            <dt>Amount</dt><dd>{{ $checkoutOrder->currency }} {{ number_format($checkoutOrder->amount_minor / 100, 2) }}</dd>
            <dt>Status</dt><dd>{{ ucfirst($checkoutOrder->status) }}</dd>
            <dt>Reservation expires</dt><dd>{{ $checkoutOrder->reservation_expires_at->format('M j, Y g:i A') }}</dd>
        </dl>

        @if ($checkoutOrder->attempts->isNotEmpty())
            <section aria-labelledby="attempt-history-title">
                <h2 id="attempt-history-title">Payment attempt history</h2>
                <ol>
                    @foreach ($checkoutOrder->attempts->sortBy('attempt_number') as $attempt)
                        <li>Test {{ ucfirst($attempt->payment_method) }} · {{ ucfirst($attempt->status) }} · {{ $attempt->created_at->format('M j, Y g:i A') }}</li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if (session('checkout_message'))
            <div class="notice" role="status">{{ session('checkout_message') }}</div>
        @endif

        @if ($checkoutOrder->status === \App\Models\CheckoutOrder::STATUS_SUCCESSFUL && $checkoutOrder->registration)
            <p>Your simulated payment succeeded. No real payment was made.</p>
            <a class="button" href="{{ route('public.tickets.show', $checkoutOrder->registration_id) }}">View ticket</a>
        @elseif (in_array($checkoutOrder->status, \App\Models\CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true))
            @php $pendingAttempt = $checkoutOrder->attempts->firstWhere('status', \App\Models\PaymentAttempt::STATUS_PENDING); @endphp
            @if ($pendingAttempt)
                <p>Test {{ ucfirst($pendingAttempt->payment_method) }} attempt is pending. Continue it after a page refresh or app restart.</p>
                <form method="POST" action="{{ route('public.checkout.process', $checkoutOrder->public_id) }}">
                    @csrf
                    <input type="hidden" name="attempt_key" value="{{ $pendingAttempt->attempt_key }}">
                    <button class="button" type="submit">Process simulated payment</button>
                </form>
            @else
                @if ($checkoutOrder->status === \App\Models\CheckoutOrder::STATUS_FAILED)
                    <p role="status">The last test attempt failed. The reservation remains active until expiry; retry or cancel it.</p>
                @endif
                <form method="POST" action="{{ route('public.checkout.attempt', $checkoutOrder->public_id) }}" style="display: flex; flex-wrap: wrap; gap: .75rem;">
                    @csrf
                    <input type="hidden" name="attempt_key" value="{{ $attemptKey }}">
                    <button class="button" type="submit" name="payment_method" value="wallet">Use Test Wallet</button>
                    <button class="button secondary" type="submit" name="payment_method" value="card">Use Test Card</button>
                </form>
            @endif
            <form method="POST" action="{{ route('public.checkout.cancel', $checkoutOrder->public_id) }}">
                @csrf
                <button class="button secondary" type="submit">Cancel checkout and release the reservation</button>
            </form>
        @else
            <p role="status">This checkout is {{ $checkoutOrder->status }}. Its reservation is no longer active.</p>
        @endif
    </section>
@endsection
