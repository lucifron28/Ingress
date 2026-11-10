<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ingress ticket {{ $registration->ticket->public_id }}</title>
    <style>
        @page { margin: 0.65in; }
        body { color: #201b1a; font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.45; }
        .brand { color: #741d2b; font-size: 11pt; font-weight: bold; letter-spacing: 2px; }
        .rule { border: 0; border-top: 2px solid #741d2b; margin: 12px 0 22px; }
        h1 { margin: 0 0 8px; color: #241d1d; font-size: 23pt; }
        .muted { color: #5f5755; }
        .details { width: 100%; margin-top: 22px; border-collapse: collapse; }
        .details th, .details td { padding: 10px 8px; border-bottom: 1px solid #ded8d4; text-align: left; vertical-align: top; }
        .details th { width: 30%; color: #5f5755; font-weight: normal; }
        .qr { margin: 24px 0 12px; text-align: center; }
        .qr img { width: 250px; height: 250px; }
        .ticket-id { color: #741d2b; font-size: 15pt; font-weight: bold; letter-spacing: 1px; text-align: center; }
        .notice { margin-top: 18px; padding: 10px; border: 1px solid #bb9553; color: #5c451d; text-align: center; font-weight: bold; }
        .footer { margin-top: 26px; color: #5f5755; font-size: 9pt; text-align: center; }
    </style>
</head>
<body>
    <p class="brand">INGRESS · ADMISSION TICKET</p>
    <hr class="rule">

    <h1>{{ $registration->event->title }}</h1>
    <p class="muted">{{ $registration->event->starts_at->format('l, F j, Y · g:i A') }}–{{ $registration->event->ends_at->format('g:i A') }}</p>
    @if ($registration->event->isCancelled())
        <p class="notice">This event has been cancelled. Contact the event organizer for updates.</p>
    @endif

    <table class="details">
        <tbody>
            <tr><th>Venue</th><td>{{ $registration->event->venue->name }}@if ($registration->event->venue->location), {{ $registration->event->venue->location }}@endif</td></tr>
            <tr><th>Attendee</th><td>{{ $registration->attendeeName() }}</td></tr>
            <tr><th>Ticket category</th><td>{{ $registration->ticketCategory?->name ?? 'General Admission' }}</td></tr>
            <tr><th>Ticket status</th><td>Active</td></tr>
        </tbody>
    </table>

    @if ($simulatedPayment)
        <p class="notice">SIMULATED PAYMENT — NO REAL MONEY IS CHARGED</p>
    @endif

    <div class="qr"><img src="{{ $qrDataUri }}" alt="Admission QR code"></div>
    <p class="ticket-id">{{ $registration->ticket->public_id }}</p>
    <p class="footer">Present this QR at the event gate. The QR is an admission credential; keep this ticket private.</p>
</body>
</html>
