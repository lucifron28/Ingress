<?php

namespace App\Actions;

use App\Models\Event;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmImportReceipt
{
    public function __construct(private ReadPreparedSnapshot $reader, private SnapshotEnvelope $envelope) {}

    public function execute(int $eventId, string $receipt): void
    {
        if (config('ingress.mode') !== 'cloud') {
            throw ValidationException::withMessages(['transfer' => 'Import confirmation requires the cloud runtime.']);
        }
        DB::transaction(function () use ($eventId, $receipt) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if (! $event->isPublished() || $event->snapshot_prepared_at === null) {
                throw ValidationException::withMessages(['transfer' => 'Prepare an active snapshot first.']);
            }
            $snapshot = $this->reader->execute($event)['record'];
            $node = DB::table('event_nodes')->where('id', $snapshot->target_node_id)->lockForUpdate()->first();
            if (! $node || $node->revoked_at !== null || $node->key_ciphertext === null) {
                throw ValidationException::withMessages(['transfer' => 'The target node is unavailable.']);
            }
            $expected = ['node_id' => $node->id, 'snapshot_id' => $snapshot->id,
                'event_id' => $event->id, 'version' => (int) $snapshot->version, 'sha256' => $snapshot->sha256];
            if ($this->envelope->verifyImportReceipt($receipt, Crypt::decryptString($node->key_ciphertext)) !== $expected) {
                throw ValidationException::withMessages(['transfer' => 'The receipt does not match this snapshot.']);
            }
            $staged = DB::table('event_snapshot_receipts')->where('snapshot_id', $snapshot->id)->first();
            if (! $staged || $staged->node_id !== $node->id || ! hash_equals($staged->sha256, $snapshot->sha256)) {
                throw ValidationException::withMessages(['transfer' => 'Confirm the staging receipt first.']);
            }
            DB::table('event_snapshot_import_receipts')->insertOrIgnore([
                'snapshot_id' => $snapshot->id, 'node_id' => $node->id, 'sha256' => $snapshot->sha256, 'received_at' => now(),
            ]);
            $recorded = DB::table('event_snapshot_import_receipts')->where('snapshot_id', $snapshot->id)->first();
            if (! $recorded || $recorded->node_id !== $node->id || ! hash_equals($recorded->sha256, $snapshot->sha256)) {
                throw ValidationException::withMessages(['transfer' => 'Conflicting import receipt.']);
            }
        });
    }
}
