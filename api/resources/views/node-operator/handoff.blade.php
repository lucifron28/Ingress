@extends('layouts.node-operator', ['title' => 'Event handoff'])

@section('content')
    <section class="card">
        <div class="topline">
            <div>
                <h1>Event handoff</h1>
                <p>Stage and import a cloud snapshot, then activate only the signed authority grant supplied by the cloud organizer.</p>
            </div>
            <a href="{{ route('node.operator.dashboard', absolute: false) }}">Back to console</a>
        </div>

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first('handoff') ?: $errors->first() }}</div>
        @endif

        @if ($result)
            <div class="status" role="status">
                <strong>{{ $result['title'] }}</strong>
                <p>{{ $result['message'] }}</p>
                <dl>
                    @foreach ($result['metadata'] as $label => $value)
                        <dt>{{ str_replace('_', ' ', ucfirst($label)) }}</dt>
                        <dd><code>{{ $value }}</code></dd>
                    @endforeach
                </dl>
                @if (isset($result['receipt']))
                    <label for="handoff-receipt">Copy this {{ $result['type'] === 'snapshot' ? 'staging' : 'import' }} receipt to the cloud organizer</label>
                    <textarea id="handoff-receipt" class="receipt" readonly spellcheck="false" autocomplete="off">{{ $result['receipt'] }}</textarea>
                    <button type="button" data-copy-receipt>Copy receipt</button>
                    <span data-copy-status role="status" aria-live="polite"></span>
                @endif
            </div>
        @endif

        <section class="status">
            <strong>Node preflight</strong>
            <dl>
                <dt>Node ID</dt>
                <dd><code>{{ $preflight['node_id'] }}</code></dd>
                <dt>Scanner API</dt>
                <dd><code>{{ $preflight['node_api_url'] ?? 'APP_URL must use HTTPS with a stable hostname' }}</code></dd>
                <dt>Runtime identity</dt>
                <dd>{{ $preflight['runtime_identity_matches'] ? 'Matches this node' : 'Mismatch. Stop all gates.' }}</dd>
                <dt>Database</dt>
                <dd>{{ $preflight['database_driver'] }}{{ $preflight['database_driver'] === 'pgsql' ? ' · PostgreSQL' : ' · Expected PostgreSQL for a venue node' }}</dd>
                <dt>Node sync key</dt>
                <dd>{{ $preflight['sync_key_ready'] ? 'Configured' : 'Missing or invalid. Snapshot transfer is unavailable.' }}</dd>
                <dt>Cloud authority public key</dt>
                <dd>{{ $preflight['authority_public_key_ready'] ? 'Configured' : 'Missing or invalid. Grant activation is unavailable.' }}</dd>
                <dt>Cloud signing private key</dt>
                <dd>{{ $preflight['authority_private_key_present'] ? 'Present. Stop and ask the installer to remove it.' : 'Not present on this node' }}</dd>
                <dt>Snapshot upload limits</dt>
                <dd>{{ $preflight['snapshot_upload_ready'] ? 'Ready for encrypted packages up to 16 MiB' : 'PHP upload limits are too small. Ask the installer to set upload_max_filesize=30M and post_max_size=32M.' }}</dd>
            </dl>
            <p>The console checks the HTTPS URL format; it cannot verify the venue DNS record, certificate chain, or scanner reachability. The installer must verify those before event setup.</p>
        </section>

        @if ($preflight['authority_private_key_present'] || ! $preflight['runtime_identity_matches'] || $preflight['database_driver'] !== 'pgsql')
            <div class="status warning" role="alert">
                <strong>Stop before preparing this node</strong>
                <span>The runtime identity, PostgreSQL database, or authority-key role is unsafe. Stop all gates and contact the installer.</span>
            </div>
        @endif

        @if (! $preflight['handoff_ready'])
            <div class="status warning" role="alert">
                <strong>Handoff actions are disabled</strong>
                <span>Resolve the node identity, PostgreSQL, HTTPS APP_URL, sync-key, authority public-key, and signing-key checks before uploading or importing a snapshot.</span>
            </div>
        @endif

        <section class="status">
            <strong>How the handoff works</strong>
            <ol>
                <li>On the cloud event page, download the encrypted package for this node and upload it below.</li>
                <li>Send the staging receipt to the cloud organizer. Import the staged snapshot and send the second receipt.</li>
                <li>The cloud organizer confirms both receipts and explicitly transfers authority. The cloud creates and supplies the signed grant.</li>
                <li>Upload that grant for the matching event and type ACTIVATE. The node checks its signature, node ID, event, and exact imported snapshot.</li>
            </ol>
            <p>Do not put a snapshot or grant in a normal email or shared folder. Use the approved transfer method for this event. The node never creates or signs authority grants. Once the cloud transfers authority, cloud changes are locked and there is no automatic failover.</p>
        </section>

        @if ($preflight['handoff_ready'] && ! $preflight['snapshot_upload_ready'])
            <div class="status warning" role="status">
                <strong>Snapshot upload unavailable</strong>
                <span>Configure PHP upload_max_filesize=30M and post_max_size=32M before uploading.</span>
            </div>
        @elseif ($preflight['handoff_ready'])
            <form method="post" action="{{ route('node.operator.handoff.snapshot.receive', absolute: false) }}" enctype="multipart/form-data" class="handoff-row">
                @csrf
                <h2>1. Stage an encrypted snapshot</h2>
                <p>Choose the encrypted package downloaded from the cloud organizer. Maximum file size: 16 MiB. The upload is validated and processed during this request; the application does not save the uploaded file.</p>
                <label for="snapshot-upload">Encrypted snapshot package</label>
                <input id="snapshot-upload" name="snapshot" type="file" accept=".bin,application/octet-stream" required>
                <button type="submit">Stage snapshot</button>
            </form>
        @endif

        <section class="status">
            <strong>Current staged snapshots and authority</strong>
            @forelse ($snapshots as $snapshot)
                <article class="handoff-row {{ $snapshot['status'] === 'Attention required' ? 'warning' : '' }}">
                    <h3>{{ $snapshot['event_title'] }}</h3>
                    <p>Cloud event {{ $snapshot['cloud_event_id'] }} · snapshot version {{ $snapshot['version'] }}</p>
                    <p>Snapshot ID: <code>{{ $snapshot['snapshot_id'] ?? 'Missing' }}</code></p>
                    <p>SHA-256: <code>{{ $snapshot['sha256'] ?? 'Missing' }}</code></p>
                    <p><strong>{{ $snapshot['status'] }}</strong></p>
                    <p>{{ $snapshot['next_action'] }}</p>

                    @if ($snapshot['can_import'])
                        <form method="post" action="{{ route('node.operator.handoff.snapshot.import', ['cloudEventId' => $snapshot['cloud_event_id']], absolute: false) }}">
                            @csrf
                            <label for="import-{{ $snapshot['cloud_event_id'] }}">Type IMPORT to confirm this snapshot import</label>
                            <input id="import-{{ $snapshot['cloud_event_id'] }}" name="confirmation" type="text" pattern="IMPORT" autocomplete="off" required>
                            <button type="submit">{{ $snapshot['imported_current'] ? 'Show import receipt again' : 'Import snapshot and create receipt' }}</button>
                        </form>
                    @endif

                    @if ($snapshot['can_activate'])
                        <form method="post" action="{{ route('node.operator.handoff.authority.activate', ['cloudEventId' => $snapshot['cloud_event_id']], absolute: false) }}" enctype="multipart/form-data">
                            @csrf
                            <p>This step permanently transfers admission authority to this node and locks cloud event changes. The cloud organizer must confirm both receipts and provide a grant for this exact snapshot first.</p>
                            <label for="grant-{{ $snapshot['cloud_event_id'] }}">Signed authority grant from the cloud organizer</label>
                            <input id="grant-{{ $snapshot['cloud_event_id'] }}" name="grant" type="file" accept=".txt,text/plain" required>
                            <label for="activate-{{ $snapshot['cloud_event_id'] }}">Type ACTIVATE to confirm this one-way authority transfer</label>
                            <input id="activate-{{ $snapshot['cloud_event_id'] }}" name="confirmation" type="text" pattern="ACTIVATE" autocomplete="off" required>
                            <button type="submit">Verify grant and activate authority</button>
                        </form>
                    @endif
                </article>
            @empty
                <p>No encrypted snapshot is staged. Start with the cloud organizer's current package for this node.</p>
            @endforelse
        </section>

        <p><a href="{{ route('node.operator.staff.index', absolute: false) }}">Return to staff and scanner management</a></p>
    </section>

    @if ($result && isset($result['receipt']))
        <script>
            (() => {
                const button = document.querySelector('[data-copy-receipt]');
                const receipt = document.getElementById('handoff-receipt');
                const status = document.querySelector('[data-copy-status]');
                button?.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(receipt.value);
                        status.textContent = 'Receipt copied. Paste it into the cloud organizer page.';
                    } catch {
                        receipt.focus();
                        receipt.select();
                        status.textContent = 'Select the receipt text and copy it into the cloud organizer page.';
                    }
                });
            })();
        </script>
    @endif
@endsection
