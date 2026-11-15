@extends('layouts.node-operator', ['title' => 'Staff and scanners'])

@section('content')
    @php($displayTimezone = config('ingress.display_timezone', 'Asia/Manila'))
    <section class="card">
        <div class="topline">
            <div>
                <h1>Staff and scanners</h1>
                <p>Staff shown here belong to the current imported event rosters. Session counts do not show whether a phone is online.</p>
            </div>
            <a href="{{ route('node.operator.dashboard', absolute: false) }}">Back to console</a>
        </div>

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        @if (session('staff_message'))
            <div class="status" role="status">{{ session('staff_message') }}</div>
        @endif

        @if (session('credential_issued'))
            @php($credential = session('credential_issued'))
            <div class="status" role="status">
                <strong>{{ $credential['rotated'] ? 'Scanner credential rotated' : 'Scanner credential provisioned' }}</strong>
                <p>{{ $credential['staff'] }} · {{ $credential['event'] }}</p>
                @if ($credential['rotated'])
                    <p>Existing scanner sessions and unused pairing codes were invalidated.</p>
                @endif
                <p>Give this one-time credential directly to the staff member through an approved secure channel. It will disappear when you leave this page.</p>
                <code class="secret">{{ $credential['secret'] }}</code>
            </div>
        @endif

        <div class="status">
            <strong>Pairing and admission</strong>
            <span>A pairing QR starts a scanner session. Admission remains unavailable until the node has a valid signed authority grant for the event.</span>
        </div>

        @forelse ($events as $event)
            <section class="status" id="event-{{ $event['id'] }}">
                <strong>{{ $event['title'] }}</strong>
                @php($startsAtLocal = \Carbon\CarbonImmutable::parse($event['starts_at'])->setTimezone($displayTimezone))
                <p>Starts <time datetime="{{ $startsAtLocal->toIso8601String() }}">{{ $startsAtLocal->format('M j, Y g:i A') }} {{ $displayTimezone }} (UTC{{ $startsAtLocal->format('P') }})</time></p>

                @foreach ($event['staff'] as $staff)
                    <article class="staff-row" id="staff-{{ $event['id'] }}-{{ $staff['id'] }}">
                        <div>
                            <strong>{{ $staff['name'] }}</strong>
                            <p>Gate: {{ $staff['gate'] }}</p>
                            <p>Local credential: {{ $staff['has_credential'] ? 'provisioned' : 'not provisioned' }}</p>
                            <p>Unexpired scanner session records: {{ $staff['session_count'] }}. Phone connectivity is unknown.</p>
                        </div>

                        @if (! $staff['has_credential'])
                            <form method="post" action="{{ route('node.operator.staff.credential.provision', [$event['id'], $staff['id']], absolute: false) }}">
                                @csrf
                                <p>Provisioning creates a credential only when none exists. The value is shown once after this step.</p>
                                <label for="provision-{{ $event['id'] }}-{{ $staff['id'] }}">Type PROVISION to continue</label>
                                <input id="provision-{{ $event['id'] }}-{{ $staff['id'] }}" name="confirmation" type="text" pattern="PROVISION" autocomplete="off" required>
                                <button type="submit">Provision credential</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('node.operator.staff.pairing.issue', [$event['id'], $staff['id']], absolute: false) }}">
                                @csrf
                                <p>Generating a code replaces any earlier unused code for this staff member.</p>
                                <button type="submit">Generate pairing QR</button>
                            </form>

                            <form method="post" action="{{ route('node.operator.staff.credential.rotate', [$event['id'], $staff['id']], absolute: false) }}">
                                @csrf
                                <label for="rotate-{{ $event['id'] }}-{{ $staff['id'] }}">Rotation signs out all saved scanner sessions. Type ROTATE to confirm.</label>
                                <input id="rotate-{{ $event['id'] }}-{{ $staff['id'] }}" name="confirmation" type="text" pattern="ROTATE" autocomplete="off" required>
                                <button type="submit">Rotate credential</button>
                            </form>

                            <form method="post" action="{{ route('node.operator.staff.credential.revoke', [$event['id'], $staff['id']], absolute: false) }}">
                                @csrf
                                <label for="revoke-{{ $event['id'] }}-{{ $staff['id'] }}">Revocation removes the credential and signs out scanners. Type REVOKE to confirm.</label>
                                <input id="revoke-{{ $event['id'] }}-{{ $staff['id'] }}" name="confirmation" type="text" pattern="REVOKE" autocomplete="off" required>
                                <button type="submit">Revoke credential and sessions</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </section>
        @empty
            <div class="status" role="status">
                <strong>No current imported event staff</strong>
                <span>Import a current event roster before pairing scanners.</span>
            </div>
        @endforelse
    </section>
@endsection
