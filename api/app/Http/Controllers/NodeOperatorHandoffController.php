<?php

namespace App\Http\Controllers;

use App\Actions\ActivateEventAuthority;
use App\Actions\AuthorityGrant;
use App\Actions\BindRuntimeDatabase;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueImportReceipt;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Support\NodeOperatorAuditRecorder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class NodeOperatorHandoffController extends Controller
{
    private const MAX_SNAPSHOT_BYTES = 16 * 1024 * 1024;

    public function index(BindRuntimeDatabase $runtime, AuthorityGrant $grants): View
    {
        $runtime->execute();

        return $this->page($grants);
    }

    public function receiveSnapshot(
        Request $request,
        BindRuntimeDatabase $runtime,
        ReceiveSnapshot $receiver,
        SnapshotEnvelope $envelope,
        AuthorityGrant $grants,
        NodeOperatorAuditRecorder $audit,
    ): Response|RedirectResponse {
        $runtime->execute();
        if (! $this->preflight()['handoff_ready']) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.received', null, 'rejected');

            return $this->failure('Node handoff preflight failed. Check the node identity, PostgreSQL database, HTTPS APP_URL, sync key, authority public key, and signing-key role before continuing.');
        }
        $request->validate(['snapshot' => ['required', 'file', 'max:16384']]);
        $file = $request->file('snapshot');
        if (! $file || ! $file->isValid()) {
            return $this->failure('Choose a readable encrypted snapshot package.');
        }

        $contents = file_get_contents($file->getPathname(), false, null, 0, self::MAX_SNAPSHOT_BYTES + 1);
        if (! is_string($contents) || strlen($contents) > self::MAX_SNAPSHOT_BYTES) {
            return $this->failure('The snapshot package is unreadable or exceeds the 16 MiB limit.');
        }

        try {
            $receipt = $receiver->execute($contents);
            $metadata = $envelope->verifyReceipt($receipt, (string) config('ingress.node_sync_key'));
        } catch (ValidationException) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.received', null, 'rejected');

            return $this->failure('Snapshot rejected. Confirm it came from the cloud organizer for this node and is the current encrypted package.');
        }

        $localEventId = $this->localEventId((int) $metadata['event_id']);
        $audit->recordEventAction($this->operatorId(), 'snapshot.received', $localEventId);

        return $this->resultPage($grants, [
            'type' => 'snapshot',
            'title' => 'Encrypted snapshot staged',
            'message' => 'Send this staging receipt to the cloud organizer. The node has not imported the event and admission remains disabled.',
            'metadata' => $metadata,
            'receipt' => $receipt,
        ]);
    }

    public function importSnapshot(
        Request $request,
        string $cloudEventId,
        BindRuntimeDatabase $runtime,
        ImportReceivedSnapshot $import,
        IssueImportReceipt $receipts,
        AuthorityGrant $grants,
        NodeOperatorAuditRecorder $audit,
    ): Response|RedirectResponse {
        $runtime->execute();
        if (! $this->validCloudEventId($cloudEventId)) {
            return $this->failure('The staged event could not be identified. Refresh the handoff page.');
        }
        $request->validate(['confirmation' => ['required', 'in:IMPORT']]);

        $id = (int) $cloudEventId;
        $localEventId = $this->localEventId($id);
        if (! $this->preflight()['handoff_ready']) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.imported', $localEventId, 'rejected');

            return $this->failure('Node handoff preflight failed. Check the node identity, PostgreSQL database, HTTPS APP_URL, sync key, authority public key, and signing-key role before continuing.');
        }
        try {
            [$localEventId, $receipt] = DB::transaction(function () use ($id, $import, $receipts): array {
                $eventId = $import->execute($id);

                return [$eventId, $receipts->execute($id)];
            });
        } catch (ValidationException) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.imported', $localEventId, 'rejected');

            return $this->failure('Import failed. No admission authority was activated. Check that the latest staged snapshot is valid and belongs to this node.');
        }

        $snapshot = DB::table('node_event_imports')->where('cloud_event_id', $id)->first();
        if (! $snapshot || (int) $snapshot->event_id !== $localEventId) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.imported', $localEventId, 'rejected');

            return $this->failure('The import receipt could not be matched to this node event. Stop and contact the installer.');
        }

        $staged = DB::table('received_snapshots')->where('id', $snapshot->snapshot_id)->first();
        if (! $staged) {
            $audit->recordEventAction($this->operatorId(), 'snapshot.imported', $localEventId, 'rejected');

            return $this->failure('The imported snapshot record is missing. Stop and contact the installer.');
        }

        $audit->recordEventAction($this->operatorId(), 'snapshot.imported', $localEventId);

        return $this->resultPage($grants, [
            'type' => 'import',
            'title' => 'Snapshot imported on this node',
            'message' => 'Send this import receipt to the cloud organizer. Admission remains disabled until the organizer verifies both receipts, transfers authority, and provides a signed grant.',
            'metadata' => [
                'node_id' => config('ingress.node_id'),
                'snapshot_id' => $snapshot->snapshot_id,
                'event_id' => $id,
                'version' => (int) $snapshot->version,
                'sha256' => $snapshot->sha256,
            ],
            'receipt' => $receipt,
        ]);
    }

    public function activateAuthority(
        Request $request,
        string $cloudEventId,
        BindRuntimeDatabase $runtime,
        AuthorityGrant $grants,
        ActivateEventAuthority $activation,
        NodeOperatorAuditRecorder $audit,
    ): Response|RedirectResponse {
        $runtime->execute();
        if (! $this->validCloudEventId($cloudEventId)) {
            return $this->failure('The imported event could not be identified. Refresh the handoff page.');
        }

        $cloudId = (int) $cloudEventId;
        $localEventId = $this->localEventId($cloudId);
        $preflight = $this->preflight();
        if (! $preflight['handoff_ready']) {
            $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

            return $this->failure('Node handoff preflight failed. Check the node identity, PostgreSQL database, HTTPS APP_URL, sync key, authority public key, and signing-key role before continuing.');
        }

        $request->validate([
            'grant' => ['required', 'file', 'max:4'],
            'confirmation' => ['required', 'in:ACTIVATE'],
        ]);
        $file = $request->file('grant');
        if (! $file || ! $file->isValid()) {
            return $this->failure('Choose the signed authority grant file from the cloud organizer.');
        }

        $contents = file_get_contents($file->getPathname(), false, null, 0, 4097);
        $grant = is_string($contents) ? trim($contents) : '';
        if ($grant === '' || strlen($grant) > 4096) {
            $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

            return $this->failure('The grant file is unreadable or exceeds the 4 KiB limit. No authority change was made.');
        }

        $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudId)->first();
        $head = DB::table('received_event_heads')->where('event_id', $cloudId)->first();
        $staged = $import ? DB::table('received_snapshots')->where('id', $import->snapshot_id)->first() : null;
        if (! $import || ! $head || ! $staged || ! $localEventId
            || (int) $import->event_id !== $localEventId
            || (int) $head->version !== (int) $import->version
            || (int) $staged->event_id !== $cloudId
            || (int) $staged->version !== (int) $import->version
            || ! hash_equals($staged->sha256, $import->sha256)) {
            $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

            return $this->failure('The grant does not match the current imported snapshot. No authority change was made.');
        }

        $metadata = [
            'protocol' => 1,
            'node_id' => config('ingress.node_id'),
            'snapshot_id' => $import->snapshot_id,
            'event_id' => $cloudId,
            'version' => (int) $import->version,
            'sha256' => $import->sha256,
        ];
        try {
            if ($grants->verify($grant) !== $metadata) {
                $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

                return $this->failure('The signed grant is for a different node, event, or snapshot. No authority change was made.');
            }
            $activatedEventId = $activation->execute($grant);
        } catch (ValidationException) {
            $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

            return $this->failure('The grant failed node validation. No authority change was made. Confirm both receipts and the grant with the cloud organizer.');
        }

        if ($activatedEventId !== $localEventId) {
            $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId, 'rejected');

            return $this->failure('The activated event did not match this handoff. Stop all gates and contact the installer.');
        }

        $audit->recordEventAction($this->operatorId(), 'authority.activated', $localEventId);

        return $this->resultPage($grants, [
            'type' => 'authority',
            'title' => 'Node authority activated',
            'message' => 'The cloud-signed authority now names this node for the displayed snapshot. Cloud cancellation and snapshot changes are locked. There is no automatic failover or recovery. Stop all gates if node identity or authority becomes uncertain.',
            'metadata' => $metadata,
        ]);
    }

    private function page(AuthorityGrant $grants, ?array $result = null): View
    {
        $preflight = $this->preflight();

        return view('node-operator.handoff', [
            'preflight' => $preflight,
            'snapshots' => $this->snapshotRows($grants, $preflight),
            'result' => $result,
        ]);
    }

    private function resultPage(AuthorityGrant $grants, array $result): Response
    {
        $preflight = $this->preflight();

        return response()->view('node-operator.handoff', [
            'preflight' => $preflight,
            'snapshots' => $this->snapshotRows($grants, $preflight),
            'result' => $result,
        ]);
    }

    private function preflight(): array
    {
        $nodeId = config('ingress.node_id');
        $identity = DB::table('runtime_identity')->where('id', 1)->first();
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';
        $safePath = $path === '' || preg_match('#\A(?:/[A-Za-z0-9_-]+)+\z#D', $path) === 1;
        $trustedUrl = is_string($url) && strlen($url) <= 480 && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && $safePath && filter_var($url, FILTER_VALIDATE_URL) !== false;
        $syncKey = config('ingress.node_sync_key');
        $authorityPublicKey = config('ingress.authority_public_key');
        $privateKeyPresent = trim((string) config('ingress.authority_private_key')) !== '';
        $databaseDriver = DB::connection()->getDriverName();
        $identityMatches = $identity && $identity->mode === 'node' && $identity->identity === $nodeId;
        $syncKeyReady = $this->validBase64Key($syncKey, 32);
        $publicKeyReady = $this->validBase64Key($authorityPublicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

        return [
            'node_id' => is_string($nodeId) ? $nodeId : 'Not configured',
            'node_api_url' => $trustedUrl ? rtrim($url, '/').'/api/v1' : null,
            'https_url_ready' => $trustedUrl,
            'runtime_identity_matches' => (bool) $identityMatches,
            'database_driver' => $databaseDriver,
            'sync_key_ready' => $syncKeyReady,
            'authority_public_key_ready' => $publicKeyReady,
            'authority_private_key_present' => $privateKeyPresent,
            'handoff_ready' => (bool) $identityMatches && ($databaseDriver === 'pgsql' || app()->environment('testing'))
                && $trustedUrl && $syncKeyReady && $publicKeyReady && ! $privateKeyPresent,
            'snapshot_upload_ready' => $this->iniBytes(ini_get('upload_max_filesize')) >= self::MAX_SNAPSHOT_BYTES
                && $this->iniBytes(ini_get('post_max_size')) >= (17 * 1024 * 1024),
        ];
    }

    private function snapshotRows(AuthorityGrant $grants, array $preflight): array
    {
        $rows = DB::table('received_event_heads as heads')
            ->leftJoin('received_snapshots as staged', function (JoinClause $join): void {
                $join->on('staged.event_id', '=', 'heads.event_id')->on('staged.version', '=', 'heads.version');
            })
            ->leftJoin('node_event_imports as imports', 'imports.cloud_event_id', '=', 'heads.event_id')
            ->leftJoin('received_snapshots as imported_snapshots', 'imported_snapshots.id', '=', 'imports.snapshot_id')
            ->leftJoin('events', 'events.id', '=', 'imports.event_id')
            ->leftJoin('node_import_ids as event_ids', function (JoinClause $join): void {
                $join->on('event_ids.cloud_id', '=', 'heads.event_id')->where('event_ids.kind', '=', 'event');
            })
            ->leftJoin('node_event_authorities as authorities', 'authorities.cloud_event_id', '=', 'heads.event_id')
            ->where('heads.version', '>', 0)
            ->orderByDesc('heads.event_id')
            ->get([
                'heads.event_id as cloud_event_id',
                'heads.version as head_version',
                'staged.id as staged_snapshot_id',
                'staged.version as staged_version',
                'staged.sha256 as staged_sha256',
                'imports.event_id as imported_local_event_id',
                'imports.snapshot_id as imported_snapshot_id',
                'imports.version as imported_version',
                'imports.sha256 as imported_sha256',
                'imported_snapshots.event_id as imported_snapshot_event_id',
                'imported_snapshots.version as imported_snapshot_version',
                'imported_snapshots.sha256 as imported_snapshot_sha256',
                'events.title as event_title',
                'events.status as event_status',
                'events.snapshot_version as event_snapshot_version',
                'events.snapshot_prepared_at as event_snapshot_prepared_at',
                'event_ids.local_id as mapped_local_event_id',
                'authorities.event_id as authority_local_event_id',
                'authorities.snapshot_id as authority_snapshot_id',
                'authorities.version as authority_version',
                'authorities.sha256 as authority_sha256',
                'authorities.grant as authority_grant',
            ]);

        return $rows->map(function (object $row) use ($grants, $preflight): array {
            $cloudEventId = (int) $row->cloud_event_id;
            $localEventId = is_numeric($row->imported_local_event_id) ? (int) $row->imported_local_event_id : null;
            $stagedSnapshotExists = is_string($row->staged_snapshot_id)
                && is_numeric($row->staged_version) && (int) $row->staged_version === (int) $row->head_version
                && is_string($row->staged_sha256) && preg_match('/\A[a-f0-9]{64}\z/', $row->staged_sha256) === 1;
            $importedMetadataConsistent = is_numeric($row->imported_local_event_id)
                && is_numeric($row->mapped_local_event_id)
                && (int) $row->mapped_local_event_id === (int) $row->imported_local_event_id
                && is_numeric($row->imported_version) && (int) $row->imported_version > 0
                && is_string($row->imported_sha256) && preg_match('/\A[a-f0-9]{64}\z/', $row->imported_sha256) === 1
                && (int) $row->imported_snapshot_event_id === (int) $row->cloud_event_id
                && (int) $row->imported_snapshot_version === (int) $row->imported_version
                && is_string($row->imported_snapshot_sha256) && hash_equals($row->imported_sha256, $row->imported_snapshot_sha256)
                && $row->event_status === 'published'
                && (int) $row->event_snapshot_version === (int) $row->imported_version
                && $row->event_snapshot_prepared_at !== null;
            $importedOlder = $stagedSnapshotExists && $importedMetadataConsistent
                && (int) $row->imported_version < (int) $row->head_version;
            $importedCurrent = $stagedSnapshotExists && $localEventId !== null
                && $importedMetadataConsistent && (int) $row->mapped_local_event_id === $localEventId
                && $row->imported_snapshot_id === $row->staged_snapshot_id
                && (int) $row->imported_version === (int) $row->head_version
                && is_string($row->imported_sha256) && hash_equals($row->staged_sha256, $row->imported_sha256);
            $authorityState = 'none';

            if ($row->authority_local_event_id !== null) {
                $authorityState = 'invalid';
                if ($importedCurrent && (int) $row->authority_local_event_id === $localEventId
                    && $row->authority_snapshot_id === $row->imported_snapshot_id
                    && (int) $row->authority_version === (int) $row->imported_version
                    && is_string($row->authority_sha256) && hash_equals($row->authority_sha256, $row->imported_sha256)
                    && is_string($row->authority_grant)) {
                    $expected = [
                        'protocol' => 1,
                        'node_id' => config('ingress.node_id'),
                        'snapshot_id' => $row->imported_snapshot_id,
                        'event_id' => $cloudEventId,
                        'version' => (int) $row->imported_version,
                        'sha256' => $row->imported_sha256,
                    ];
                    try {
                        if ($grants->verify($row->authority_grant) === $expected) {
                            $authorityState = 'active';
                        }
                    } catch (ValidationException) {
                        $authorityState = 'invalid';
                    }
                }
            }

            $importMismatch = $row->imported_local_event_id !== null && ! $importedCurrent && ! $importedOlder;
            $integrityIssue = ! $stagedSnapshotExists || $importMismatch || $authorityState === 'invalid';

            if ($authorityState === 'active') {
                $status = 'Authority active';
                $nextAction = 'No more handoff action is available. Do not activate a second grant.';
            } elseif ($integrityIssue) {
                $status = 'Attention required';
                $nextAction = 'Stop all gates and contact the installer. The staged snapshot or authority record did not match.';
            } elseif ($importedOlder) {
                $status = 'Newer snapshot staged; import required';
                $nextAction = 'Import the newer snapshot, then send its import receipt to the cloud organizer.';
            } elseif ($importedCurrent) {
                $status = 'Imported; awaiting cloud organizer';
                $nextAction = 'Send both receipts to the cloud organizer. Upload a grant only after the organizer confirms both receipts and transfers authority.';
            } else {
                $status = 'Staged; import required';
                $nextAction = 'Import this staged snapshot, then send the import receipt to the cloud organizer.';
            }

            return [
                'cloud_event_id' => $cloudEventId,
                'event_title' => is_string($row->event_title) ? $row->event_title : "Cloud event {$cloudEventId}",
                'snapshot_id' => $row->staged_snapshot_id,
                'version' => (int) $row->head_version,
                'sha256' => $row->staged_sha256,
                'imported_current' => $importedCurrent,
                'authority_state' => $authorityState,
                'status' => $status,
                'next_action' => $nextAction,
                'can_import' => $preflight['handoff_ready'] && $stagedSnapshotExists && ! $integrityIssue && $authorityState === 'none',
                'can_activate' => $preflight['handoff_ready'] && $importedCurrent && $authorityState === 'none'
                    && $preflight['authority_public_key_ready'],
            ];
        })->values()->all();
    }

    private function validCloudEventId(string $id): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $id) === 1 && (string) (int) $id === $id;
    }

    private function localEventId(int $cloudEventId): ?int
    {
        $localId = DB::table('node_import_ids')->where('kind', 'event')->where('cloud_id', $cloudEventId)->value('local_id');

        return is_numeric($localId) && (int) $localId > 0 ? (int) $localId : null;
    }

    private function validBase64Key(mixed $value, int $length): bool
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;

        return is_string($decoded) && strlen($decoded) === $length && hash_equals(base64_encode($decoded), $value);
    }

    private function iniBytes(string|false $value): int
    {
        if (! is_string($value) || trim($value) === '') {
            return 0;
        }

        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $size = (float) $value;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) ($size * $multiplier);
    }

    private function operatorId(): int
    {
        $operatorId = Auth::guard('node_operator')->id();
        abort_unless(is_numeric($operatorId) && (int) $operatorId > 0, 401);

        return (int) $operatorId;
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->to(route('node.operator.handoff.index', absolute: false))
            ->withErrors(['handoff' => $message]);
    }
}
