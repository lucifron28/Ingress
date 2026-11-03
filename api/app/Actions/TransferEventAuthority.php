<?php

namespace App\Actions;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferEventAuthority
{
    public function __construct(private ReadPreparedSnapshot $reader, private AuthorityGrant $grants) {}

    public function execute(int $eventId): string
    {
        if (config('ingress.mode') !== 'cloud') {
            throw ValidationException::withMessages(['transfer' => 'Authority transfer requires the cloud runtime.']);
        }

        return DB::transaction(function () use ($eventId) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('cloud_event_authorities')->where('event_id', $eventId)->first();
            if ($existing) {
                return $existing->grant;
            }
            if (! $event->isPublished() || $event->snapshot_prepared_at === null) {
                throw ValidationException::withMessages(['transfer' => 'Prepare an active snapshot first.']);
            }
            $snapshot = $this->reader->execute($event)['record'];
            $node = DB::table('event_nodes')->where('id', $snapshot->target_node_id)->lockForUpdate()->first();
            if (! $node || $node->revoked_at !== null || $node->key_ciphertext === null) {
                throw ValidationException::withMessages(['transfer' => 'The target node is unavailable.']);
            }
            foreach (['event_snapshot_receipts', 'event_snapshot_import_receipts'] as $table) {
                $receipt = DB::table($table)->where('snapshot_id', $snapshot->id)->first();
                if (! $receipt || $receipt->node_id !== $node->id || ! hash_equals($snapshot->sha256, $receipt->sha256)) {
                    throw ValidationException::withMessages(['transfer' => 'Confirm both node receipts first.']);
                }
            }
            $grant = $this->grants->sign([
                'protocol' => 1, 'node_id' => $node->id, 'snapshot_id' => $snapshot->id,
                'event_id' => $event->id, 'version' => (int) $snapshot->version, 'sha256' => $snapshot->sha256,
            ]);
            DB::table('cloud_event_authorities')->insert([
                'event_id' => $eventId, 'snapshot_id' => $snapshot->id, 'node_id' => $node->id,
                'grant' => $grant, 'issued_at' => now(),
            ]);

            return $grant;
        });
    }
}
