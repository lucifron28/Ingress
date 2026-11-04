<?php

namespace App\Actions;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueImportReceipt
{
    public function __construct(private BindRuntimeDatabase $runtime, private SnapshotEnvelope $envelope) {}

    public function execute(int $cloudEventId): string
    {
        $nodeId = config('ingress.node_id');
        $secret = config('ingress.node_sync_key');
        if (config('ingress.mode') !== 'node' || $cloudEventId < 1 || ! is_string($nodeId) || ! is_string($secret)) {
            throw ValidationException::withMessages(['transfer' => 'Node transfer credentials are not configured.']);
        }
        $this->runtime->execute();

        return DB::transaction(function () use ($cloudEventId, $nodeId, $secret) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $head = DB::table('received_event_heads')->where('event_id', $cloudEventId)->lockForUpdate()->first();
            $import = DB::table('node_event_imports')->where('cloud_event_id', $cloudEventId)->first();
            $event = $import ? DB::table('events')->where('id', $import->event_id)->lockForUpdate()->first() : null;
            $snapshot = $import ? DB::table('received_snapshots')->where('id', $import->snapshot_id)->first() : null;
            $link = DB::table('node_import_ids')->where('kind', 'event')->where('cloud_id', $cloudEventId)->first();
            if (! $head || ! $import || ! $event || ! $snapshot || ! $link
                || (int) $link->local_id !== (int) $event->id
                || (int) $head->version !== (int) $import->version
                || (int) $snapshot->event_id !== $cloudEventId || (int) $snapshot->version !== (int) $import->version
                || ! in_array((int) $snapshot->schema_version, [1, 2, 3, 4], true) || ! hash_equals($snapshot->sha256, $import->sha256)
                || ! hash_equals($snapshot->sha256, hash('sha256', Crypt::decryptString($snapshot->payload_ciphertext)))
                || $event->status !== 'published' || $event->snapshot_prepared_at === null
                || (int) $event->snapshot_version !== (int) $import->version) {
                throw ValidationException::withMessages(['transfer' => 'The current snapshot is not imported on this node.']);
            }

            return $this->envelope->importReceipt([
                'node_id' => $nodeId, 'snapshot_id' => $import->snapshot_id, 'event_id' => $cloudEventId,
                'version' => (int) $import->version, 'sha256' => $import->sha256,
            ], $secret);
        });
    }
}
