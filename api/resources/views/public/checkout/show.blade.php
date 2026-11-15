@extends('layouts.public')

@section('content')
    @php
        $reservationIsActive = in_array($checkoutOrder->status, \App\Models\CheckoutOrder::ACTIVE_RESERVATION_STATUSES, true);
        $pendingAttempt = $checkoutOrder->attempts->firstWhere('status', \App\Models\PaymentAttempt::STATUS_PENDING);
        $statusTone = match ($checkoutOrder->status) {
            'successful' => 'success',
            'failed' => 'error',
            'pending' => 'warning',
            default => 'neutral',
        };
    @endphp

    <nav class="public-breadcrumbs" aria-label="Breadcrumb">
        <a href="{{ route('public.events.show', $checkoutOrder->event->slug) }}">{{ $checkoutOrder->event->title }}</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Simulated checkout</span>
    </nav>

    <div class="public-narrow-page">
        <header class="public-page-header public-page-header--compact">
            <p class="public-eyebrow">Checkout</p>
            <h1 class="public-page-title">Complete your ticket reservation</h1>
        </header>

        <div class="public-notice public-notice--simulated" role="note" aria-labelledby="simulated-payment-title">
            <h2 id="simulated-payment-title">SIMULATED PAYMENT — NO REAL MONEY IS CHARGED.</h2>
            <p>This demonstration uses test wallet and card labels only. Do not enter card numbers, security codes, PINs, or one-time codes.</p>
        </div>

        <section class="public-panel public-checkout-section" aria-labelledby="order-summary-title">
            <div class="public-section-heading">
                <p class="public-eyebrow">Order summary</p>
                <h2 id="order-summary-title">Your ticket</h2>
            </div>
            <dl class="public-checkout-summary">
                <div>
                    <dt>Event</dt>
                    <dd>{{ $checkoutOrder->event->title }}</dd>
                </div>
                <div>
                    <dt>Ticket category</dt>
                    <dd>{{ $checkoutOrder->ticketCategory->name }}</dd>
                </div>
                <div class="public-checkout-summary__amount">
                    <dt>Total amount</dt>
                    <dd>{{ $checkoutOrder->currency }} {{ number_format($checkoutOrder->amount_minor / 100, 2) }}</dd>
                </div>
            </dl>
        </section>

        <section class="public-panel public-checkout-section" aria-labelledby="reservation-status-title">
            <div class="public-section-heading">
                <p class="public-eyebrow">Reservation</p>
                <h2 id="reservation-status-title">Reservation status</h2>
            </div>
            <dl class="public-checkout-reservation">
                <div>
                    <dt>Status</dt>
                    <dd><span class="badge badge-{{ $statusTone }}">{{ ucfirst($checkoutOrder->status) }}</span></dd>
                </div>
                <div>
                    <dt>Reservation expires</dt>
                    <dd><time datetime="{{ $checkoutOrder->reservation_expires_at->toIso8601String() }}">{{ $checkoutOrder->reservation_expires_at->format('M j, Y · g:i A') }} PHT</time></dd>
                </div>
            </dl>
            @if (! $reservationIsActive)
                <p class="public-secondary-text">This reservation is no longer active.</p>
            @endif
        </section>

        @if (session('checkout_message'))
            <div class="public-notice" role="status">{{ session('checkout_message') }}</div>
        @endif

        @if ($checkoutOrder->status === \App\Models\CheckoutOrder::STATUS_SUCCESSFUL && $checkoutOrder->registration)
            <section class="public-panel public-checkout-section" aria-labelledby="checkout-success-title">
                <h2 id="checkout-success-title">Ticket issued</h2>
                <p>Your simulated payment succeeded. No real payment was made.</p>
                <x-ui.button :href="route('public.tickets.show', $checkoutOrder->registration_id)"><span>View ticket</span></x-ui.button>
            </section>
        @elseif ($reservationIsActive)
            <section class="public-panel public-checkout-section" aria-labelledby="payment-controls-title">
                <div class="public-section-heading">
                    <p class="public-eyebrow">Test payment controls</p>
                    <h2 id="payment-controls-title">Choose a simulated outcome</h2>
                </div>

                @if ($pendingAttempt)
                    <p class="public-notice" role="status">
                        Test {{ ucfirst($pendingAttempt->payment_method) }} is pending with a simulated {{ $pendingAttempt->simulated_outcome }} result. You can continue this attempt after a page refresh or app restart.
                    </p>
                    <form method="POST" action="{{ route('public.checkout.process', $checkoutOrder->public_id) }}">
                        @csrf
                        <input type="hidden" name="attempt_key" value="{{ $pendingAttempt->attempt_key }}">
                        <x-ui.button type="submit"><span>Process simulated payment</span></x-ui.button>
                    </form>
                @else
                    @if ($checkoutOrder->status === \App\Models\CheckoutOrder::STATUS_FAILED)
                        <p class="public-notice public-notice--error" role="status">The last test attempt failed. Your reservation remains active until expiry; you can retry or cancel it.</p>
                    @endif
                    <form class="public-checkout-controls" method="POST" action="{{ route('public.checkout.attempt', $checkoutOrder->public_id) }}">
                        @csrf
                        <input type="hidden" name="attempt_key" value="{{ $attemptKey }}">
                        <div class="form-group public-checkout-controls__outcome">
                            <label class="form-label" for="simulated_outcome">Demo outcome <span class="required-indicator" aria-hidden="true">*</span></label>
                            <select class="form-select" id="simulated_outcome" name="simulated_outcome" required @error('simulated_outcome') aria-invalid="true" aria-describedby="simulated-outcome-error" @enderror>
                                <option value="" selected disabled>Choose the result to simulate</option>
                                <option value="success">Successful payment</option>
                                <option value="failure">Failed payment</option>
                            </select>
                            @error('simulated_outcome')
                                <p id="simulated-outcome-error" class="field-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="public-checkout-controls__actions">
                            <x-ui.button type="submit" name="payment_method" value="wallet"><span>Use Test Wallet</span></x-ui.button>
                            <x-ui.button type="submit" name="payment_method" value="card" variant="secondary"><span>Use Test Card</span></x-ui.button>
                        </div>
                    </form>
                @endif
            </section>
        @else
            <div class="public-notice" role="status">This checkout is {{ $checkoutOrder->status }}. Its reservation is no longer active.</div>
        @endif

        <section class="public-panel public-checkout-section" aria-labelledby="payment-history-title">
            <div class="public-section-heading">
                <p class="public-eyebrow">History</p>
                <h2 id="payment-history-title">Payment attempts</h2>
            </div>
            @if ($checkoutOrder->attempts->isNotEmpty())
                <ol class="public-payment-history">
                    @foreach ($checkoutOrder->attempts->sortBy('attempt_number') as $attempt)
                        <li>
                            <strong>Test {{ ucfirst($attempt->payment_method) }}</strong>
                            <span>Simulated {{ $attempt->simulated_outcome }} · {{ ucfirst($attempt->status) }}</span>
                            <time datetime="{{ $attempt->created_at->toIso8601String() }}">{{ $attempt->created_at->format('M j, Y · g:i A') }} PHT</time>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="public-secondary-text">No payment attempts yet.</p>
            @endif
        </section>

        @if ($reservationIsActive)
            <form method="POST" action="{{ route('public.checkout.cancel', $checkoutOrder->public_id) }}" class="public-checkout-cancel">
                @csrf
                <x-ui.button type="submit" variant="secondary"><span>Cancel checkout and release the reservation</span></x-ui.button>
            </form>
        @endif
    </div>
@endsection
