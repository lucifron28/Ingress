@extends('layouts.public')

@section('content')
    <section class="card pass">
        <p class="eyebrow">Ingress admission pass</p>
        @if ($registration->event->isCancelled())
            <div class="notice">This event has been cancelled. Contact the event organizer for updates.</div>
        @endif
        <h1 style="font-size: 2rem;">{{ $registration->event->title }}</h1>
        <p class="meta">{{ $registration->event->starts_at->format('l, F j, Y · g:i A') }}</p>
        <p><strong>{{ $registration->event->venue->name }}</strong></p>
        <p>{{ $registration->attendeeName() }}</p>
        @if ($registration->ticketCategory)<p class="meta">{{ $registration->ticketCategory->name }}</p>@endif
        <div class="pass-qr">{!! $qr !!}</div>
        <p class="eyebrow">Ticket reference</p>
        <p class="mono" style="font-weight: 700;">{{ $registration->ticket->public_id }}</p>
        <p class="meta screen-only">Present this QR at the event gate. Keep the pass private; anyone holding the QR can present it for admission.</p>
    </section>
@endsection
