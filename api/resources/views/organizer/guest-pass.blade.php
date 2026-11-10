<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guest pass - {{ $event->title }}</title>
    <style>
        body { font-family: sans-serif; color: #111827; margin: 2rem auto; max-width: 30rem; padding: 0 1rem; }
        .pass { border: 2px solid #800000; border-radius: 8px; padding: 1.5rem; text-align: center; }
        .qr { width: 100%; max-width: 20rem; margin: 1rem auto; }
        .qr svg { width: 100%; height: auto; }
        .hint { color: #475569; line-height: 1.5; }
        @media print { .hint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="hint">Print this pass or save it securely for the attendee. Anyone with the QR can present it at the gate.</p>
    <main class="pass">
        <p>INGRESS &middot; {{ strtoupper($registration->attendee_type) }}</p>
        <h1>{{ $event->title }}</h1>
        <p>{{ $registration->attendeeName() }}</p>
        <div class="qr">{!! $qr !!}</div>
        <p>Ticket reference: {{ $registration->ticket->public_id }}</p>
        @if ($redemptionCode)
            <section class="hint" aria-live="polite">
                <h2>Private transfer code</h2>
                <p>Possession of this code grants ticket transfer control.</p>
                <p>The code is shown once. Send it to the attendee through a private channel and tell them to save it separately from the admission QR.</p>
                <p><code id="redemption-code">{{ $redemptionCode }}</code></p>
            </section>
        @endif
    </main>
</body>
</html>
