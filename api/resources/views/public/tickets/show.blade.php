@extends('layouts.public')

@section('content')
    <article class="public-admission-ticket" aria-labelledby="admission-ticket-title">
        <header class="public-admission-ticket__brand">
            <x-app-logo variant="horizontal" alt="Ingress" class="public-admission-ticket__logo" />
            <p class="public-eyebrow">Digital admission ticket</p>
        </header>

        @if ($registration->event->isCancelled())
            <div class="public-notice public-notice--error screen-only" role="status">
                <strong>This event has been cancelled.</strong>
                <span>Contact the event organizer for updates.</span>
            </div>
        @endif

        <h1 id="admission-ticket-title" class="public-admission-ticket__title">{{ $registration->event->title }}</h1>

        <dl class="public-admission-facts">
            <div>
                <dt>Date and time (PHT)</dt>
                <dd><time datetime="{{ \App\Support\EventSchedule::local($registration->event->starts_at)->toIso8601String() }}">{{ \App\Support\EventSchedule::local($registration->event->starts_at)->format('l, F j, Y · g:i A') }} PHT</time></dd>
            </div>
            <div>
                <dt>Venue</dt>
                <dd>{{ $registration->event->venue->name }}</dd>
            </div>
            <div>
                <dt>Attendee</dt>
                <dd>{{ $registration->attendeeName() }}</dd>
            </div>
            @if ($registration->ticketCategory)
                <div>
                    <dt>Ticket category</dt>
                    <dd>{{ $registration->ticketCategory->name }}</dd>
                </div>
            @endif
        </dl>

        <section class="public-admission-qr" aria-labelledby="admission-qr-heading">
            <h2 id="admission-qr-heading">Admission QR code</h2>
            <div class="public-admission-qr__frame">{!! $qr !!}</div>
            <p>Present this QR at the event gate. Keep this ticket private; anyone holding the QR can present it for admission.</p>
        </section>

        <div class="public-admission-ticket__reference">
            <span class="public-field-label">Ticket reference</span>
            <strong class="mono">{{ $registration->ticket->public_id }}</strong>
        </div>
        <p class="public-admission-ticket__status"><span class="badge badge-success">Ticket status: Active</span></p>

        <div class="public-admission-ticket__actions screen-only">
            <x-ui.button :href="route('public.tickets.pdf', $registration)"><span>Download PDF ticket</span></x-ui.button>
            <a href="{{ route('public.tickets.redeem') }}">Transfer a guest ticket with a redemption code</a>
        </div>
    </article>

    @if ($redemptionCode)
        <section class="public-private-transfer screen-only" aria-labelledby="private-transfer-title" aria-live="polite">
            <p class="public-eyebrow">Private credential</p>
            <h2 id="private-transfer-title">Private transfer code</h2>
            <p>Possession of this code grants ticket transfer control.</p>
            <p>The code is shown once. Save it separately from the admission QR.</p>
            <p>There is no public reset. If you lose it or think someone copied it, contact the event organizer. A manager may reissue credentials after verifying your identity.</p>
            <p class="public-private-transfer__code"><code id="redemption-code">{{ $redemptionCode }}</code></p>
            <p>Do not include this code in a screenshot of your admission QR or share it in a public channel.</p>
        </section>
    @endif
@endsection
