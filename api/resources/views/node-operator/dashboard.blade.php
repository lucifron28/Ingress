@extends('layouts.node-operator', ['title' => 'Operator console'])

@section('content')
    @php($displayTimezone = config('ingress.display_timezone', 'Asia/Manila'))
    <section class="card">
        <div class="topline">
            <div>
                <h1>Operator console</h1>
                <p>Signed in as {{ $operator->name }}.</p>
            </div>
            <form method="post" action="{{ route('node.operator.logout', absolute: false) }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </div>

        @if (session('syncStatus'))
            <div class="status {{ session('syncStatus.kind') === 'warning' ? 'warning' : '' }}" role="status">
                <strong>{{ session('syncStatus.kind') === 'warning' ? 'Synchronization status' : 'Sync pass finished' }}</strong>
                <span>{{ session('syncStatus.message') }}</span>
            </div>
        @endif

        @if ($operations['authorityUncertain'])
            <div class="status warning" role="alert">
                <strong>Stop all gates</strong>
                <span>At least one event authority no longer matches the current imported snapshot or signed grant. Do not scan or sync until the installer and organizer have reviewed the node.</span>
            </div>
        @elseif (! $operations['hasActiveAuthority'])
            <div class="status warning" role="status">
                <strong>No active event authority</strong>
                <span>This node has no activated event. Admissions are unavailable until the organizer completes the signed handoff.</span>
            </div>
        @else
            <div class="status" role="status">
                <strong>Node identity and event authority verified</strong>
                <span>Counts below are local records waiting for cloud acknowledgment at the time this page loaded.</span>
            </div>
        @endif

        <h2>Node status</h2>
        <dl>
            <dt>Node ID</dt><dd>{{ $operations['nodeId'] }}</dd>
            <dt>Configured host</dt><dd>{{ $operations['host'] }}</dd>
            <dt>Runtime profile</dt><dd>{{ $operations['runtimeProfile'] }}</dd>
            <dt>TLS certificate</dt><dd>Not checked here. The installer must verify the trusted certificate and hostname from scanner devices.</dd>
            <dt>Database</dt><dd>Connected ({{ $operations['databaseDriver'] }}); runtime identity check passed</dd>
            <dt>Cloud sync endpoint</dt>
            <dd>{{ $operations['syncConfigured'] ? 'HTTPS endpoint and sync key are configured; connectivity is not tested on this page' : 'Not safely configured' }}</dd>
            <dt>Scheduler</dt><dd>Not checked from this page. The installer must confirm the host scheduler is running.</dd>
            <dt>Scanner access</dt><dd>Not checked from this page. Test HTTPS from each scanner on the venue LAN.</dd>
        </dl>

        <h2>Configured events</h2>
        @forelse ($operations['events'] as $event)
            <article class="card">
                <h3>{{ $event['title'] }}</h3>
                <p>Cloud event {{ $event['cloudId'] }} · Snapshot version {{ $event['snapshotVersion'] }}</p>
                @if ($event['authorityValid'])
                    <p>Authority: verified for this node.</p>
                    <p>Local records</p>
                    <ul>
                        <li>Admission movements: {{ $event['local']['admissions'] }}</li>
                        <li>Walk-in passes issued: {{ $event['local']['walkIns'] }}</li>
                        <li>Walk-in recoveries: {{ $event['local']['recoveries'] }}</li>
                    </ul>
                    <p>Waiting for cloud acknowledgment</p>
                    <ul>
                        <li>Admissions waiting for acknowledgment: {{ $event['pending']['admissions'] }}</li>
                        <li>Walk-in issues waiting for acknowledgment: {{ $event['pending']['walkIns'] }}</li>
                        <li>Walk-in recoveries waiting for acknowledgment: {{ $event['pending']['recoveries'] }}</li>
                        <li>Most recent acknowledged record:
                            @if ($event['lastAcknowledgedAt'])
                                @php($acknowledgedAtLocal = $event['lastAcknowledgedAt']->setTimezone($displayTimezone))
                                <time datetime="{{ $acknowledgedAtLocal->toIso8601String() }}">{{ $acknowledgedAtLocal->format('M j, Y g:i:s A') }} {{ $displayTimezone }} (UTC{{ $acknowledgedAtLocal->format('P') }})</time>
                            @else
                                None recorded yet
                            @endif
                        </li>
                        <li>Last sync pass result:
                            @if ($event['lastSyncRun'])
                                @switch($event['lastSyncRun']['result'])
                                    @case('succeeded') Completed without a reported error @break
                                    @case('busy') Not started; another pass held the lock @break
                                    @default Failed; pending records remain on this node
                                @endswitch
                                @php($syncRunAtLocal = $event['lastSyncRun']['recordedAt']->setTimezone($displayTimezone))
                                at <time datetime="{{ $syncRunAtLocal->toIso8601String() }}">{{ $syncRunAtLocal->format('M j, Y g:i:s A') }} {{ $displayTimezone }} (UTC{{ $syncRunAtLocal->format('P') }})</time>
                            @else
                                No result recorded yet
                            @endif
                        </li>
                    </ul>
                    <p>This timestamp records the latest individual cloud acknowledgment. It does not mean every record is synchronized.</p>
                    <p>A pass marked completed without a reported error does not prove cloud connectivity if nothing was pending to send.</p>
                    @if ($event['pending']['total'] === 0)
                        <p>No pending records to send. The cloud connection is not tested when there is nothing to synchronize.</p>
                    @elseif ($operations['canSync'])
                        <form method="post" action="{{ route('node.operator.events.sync', ['event' => $event['localId']], absolute: false) }}">
                            @csrf
                            <button type="submit">Run one bounded sync pass</button>
                        </form>
                    @else
                        <p role="status">Sync Now is disabled while sync settings or another event's authority need review.</p>
                    @endif
                @else
                    <p class="status warning" role="alert">Authority uncertain. Stop all gates and contact the installer and organizer.</p>
                @endif
            </article>
        @empty
            <p>No event is configured on this node.</p>
        @endforelse

        <h2>Event closeout checklist</h2>
        <ol>
            <li>Stop new scans at every gate and tell staff the event is closing.</li>
            <li>Finish or cancel every open scan preview on each scanner.</li>
            <li>Restore venue internet. Run one sync pass, refresh the counts, and repeat as needed until all three pending counts are zero.</li>
            <li>Compare node and cloud admission, walk-in, and recovery totals with the organizer. Stop if any count or authority does not match.</li>
            <li>Have the installer confirm the host scheduler has completed its final run.</li>
            <li>Preserve the node database, application key, sync secret, signed authority grant, and relevant logs in the venue's protected backup location.</li>
            <li>After reconciliation and backup, follow the selected WSL or IIS host shutdown instructions in the deployment guide.</li>
        </ol>
        <p>If database, certificate, node identity, authority, or counts are uncertain, stop all gates. There is no automatic failover or shutdown action in this console.</p>

        <p><a href="{{ route('node.operator.staff.index', absolute: false) }}">Open staff and scanner management</a></p>
        <p><a href="{{ route('node.operator.handoff.index', absolute: false) }}">Open event handoff</a></p>
    </section>
@endsection
