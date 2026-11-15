<?php

namespace App\Actions;

use App\Models\Event;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExportSnapshot
{
    public function __construct(private ReadPreparedSnapshot $reader, private SnapshotEnvelope $envelope) {}

    public function execute(int $eventId, string $nodeId): string
    {
        if (config('ingress.mode') !== 'cloud') {
            throw ValidationException::withMessages(['transfer' => 'Snapshot export requires the cloud runtime.']);
        }

        return DB::transaction(function () use ($eventId, $nodeId) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if (! $event->isPublished() || $event->snapshot_prepared_at === null) {
                throw ValidationException::withMessages(['transfer' => 'Prepare an active snapshot first.']);
            }
            $snapshot = $this->reader->execute($event);
            $record = $snapshot['record'];
            $node = DB::table('event_nodes')->where('id', $nodeId)->lockForUpdate()->first();
            if (! $node || $node->revoked_at !== null || $node->key_ciphertext === null) {
                throw ValidationException::withMessages(['transfer' => 'The selected node is unavailable.']);
            }
            if ($record->target_node_id !== null && $record->target_node_id !== $nodeId) {
                throw ValidationException::withMessages(['transfer' => 'This snapshot is already bound to another node.']);
            }
            try {
                $nodeKey = Crypt::decryptString($node->key_ciphertext);
            } catch (DecryptException) {
                throw ValidationException::withMessages([
                    'transfer' => 'The saved Event Node transfer credential could not be decrypted. Verify that the current Cloud APP_KEY matches the key used when this node was registered; if it changed, restore that original key before retrying.',
                ]);
            }

            $sealed = $this->envelope->seal($nodeId, $nodeKey, $snapshot['json']);
            DB::table('event_snapshots')->where('id', $record->id)->update(['target_node_id' => $nodeId, 'updated_at' => now()]);

            return $sealed;
        });
    }
}
