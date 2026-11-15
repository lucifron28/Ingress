@extends('layouts.node-operator', ['title' => 'Pair a scanner'])

@section('content')
    <section class="card">
        <h1>Pair this scanner</h1>
        <p>Staff member: <strong>{{ $staff->name }}</strong></p>
        <p>Event: {{ $event->title }}<br>Gate: {{ $gate }}</p>
        <p>Node identity: <strong>{{ $nodeId }}</strong><br>Scanner address: {{ $nodeApiUrl }}</p>
        <p>Show this QR only to the selected staff member. It contains a one-use pairing secret and expires after three minutes. Do not screenshot or share it.</p>

        <div class="qr-frame">
            <img id="pairing-qr" src="{{ $qrDataUri }}" alt="Temporary one-use scanner pairing QR code" width="360" height="360" decoding="sync">
        </div>

        @php
            $displayTimezone = config('ingress.display_timezone', 'Asia/Manila');
            $expiresAtLocal = \Carbon\CarbonImmutable::parse($expiresAt)->setTimezone($displayTimezone);
        @endphp
        <p>Expires at <time datetime="{{ $expiresAtLocal->toIso8601String() }}">{{ $expiresAtLocal->format('M j, Y g:i:s A') }} {{ $displayTimezone }} (UTC{{ $expiresAtLocal->format('P') }})</time>. Remaining time: <strong data-countdown data-seconds="{{ $secondsRemaining }}"></strong>.</p>
        <p>The node enforces the expiry and one-use limit. The phone must show the same node identity and address before staff confirms pairing.</p>
        @if (str_starts_with($nodeApiUrl, 'http://'))
            <p>This LAN-demo pairing uses unencrypted HTTP on the private venue network. Only use it on an isolated network controlled by the operator.</p>
        @else
            <p>Confirm the phone shows the node's HTTPS address before staff accepts pairing.</p>
        @endif

        <form method="post" action="{{ route('node.operator.staff.pairing.cancel', [$event->id, $staff->id], absolute: false) }}">
            @csrf
            <label for="cancel-pairing">Type CANCEL to invalidate this unused code. If it was already scanned, revoke the staff credential to end those sessions.</label>
            <input id="cancel-pairing" name="confirmation" type="text" pattern="CANCEL" autocomplete="off" required>
            <button type="submit">Cancel unused pairing code</button>
        </form>

        <p><a href="{{ route('node.operator.staff.index', absolute: false) }}">Return to staff management</a></p>
    </section>

    <script>
        (() => {
            const countdown = document.querySelector('[data-countdown]');
            const qr = document.getElementById('pairing-qr');
            let remaining = Number(countdown?.dataset.seconds || 0);
            const update = () => {
                if (!countdown) return;
                if (remaining <= 0) {
                    countdown.textContent = 'expired';
                    if (qr) qr.hidden = true;
                    return;
                }
                countdown.textContent = `${Math.floor(remaining / 60)}m ${remaining % 60}s`;
            };
            update();
            window.setInterval(() => {
                remaining = Math.max(0, remaining - 1);
                update();
            }, 1000);
        })();
    </script>
@endsection
