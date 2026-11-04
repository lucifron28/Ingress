<?php

namespace App\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveSnapshot
{
    public function __construct(private BindRuntimeDatabase $runtime, private SnapshotEnvelope $envelope) {}

    public function execute(string $sealed): string
    {
        $nodeId = config('ingress.node_id');
        $secret = config('ingress.node_sync_key');
        if (config('ingress.mode') !== 'node' || ! is_string($nodeId) || ! is_string($secret)) {
            throw ValidationException::withMessages(['transfer' => 'Node transfer credentials are not configured.']);
        }
        $this->runtime->execute();
        $opened = $this->envelope->open($nodeId, $secret, $sealed);
        $metadata = $opened['metadata'];
        $schemaVersion = json_decode($opened['json'], true, 512, JSON_THROW_ON_ERROR)['schema_version'];
        try {
            DB::transaction(function () use ($metadata, $opened, $schemaVersion) {
                DB::table('received_event_heads')->insertOrIgnore(['event_id' => $metadata['event_id'], 'version' => 0]);
                $head = DB::table('received_event_heads')->where('event_id', $metadata['event_id'])->lockForUpdate()->first();
                $authority = DB::table('node_event_authorities')->where('cloud_event_id', $metadata['event_id'])->first();
                if ($authority && ($authority->snapshot_id !== $metadata['snapshot_id']
                    || (int) $authority->version !== $metadata['version'] || ! hash_equals($authority->sha256, $metadata['sha256']))) {
                    throw ValidationException::withMessages(['transfer' => 'This event has active node authority.']);
                }
                if ($metadata['version'] < (int) $head->version) {
                    throw ValidationException::withMessages(['transfer' => 'A newer snapshot has already been received.']);
                }
                $existing = DB::table('received_snapshots')->where('id', $metadata['snapshot_id'])->first();
                if ($existing) {
                    if ((int) $head->version !== $metadata['version']
                        || (int) $existing->event_id !== $metadata['event_id'] || (int) $existing->version !== $metadata['version']
                        || (int) $existing->schema_version !== $schemaVersion
                        || ! hash_equals($existing->sha256, $metadata['sha256'])
                        || ! hash_equals($existing->sha256, hash('sha256', Crypt::decryptString($existing->payload_ciphertext)))) {
                        throw ValidationException::withMessages(['transfer' => 'Conflicting snapshot.']);
                    }

                    return;
                }
                if ($metadata['version'] === (int) $head->version) {
                    throw ValidationException::withMessages(['transfer' => 'Conflicting snapshot version.']);
                }
                DB::table('received_snapshots')->insert([
                    'id' => $metadata['snapshot_id'], 'event_id' => $metadata['event_id'], 'version' => $metadata['version'],
                    'schema_version' => $schemaVersion, 'sha256' => $metadata['sha256'],
                    'payload_ciphertext' => Crypt::encryptString($opened['json']), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('received_event_heads')->where('event_id', $metadata['event_id'])->update(['version' => $metadata['version']]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['transfer' => 'Conflicting snapshot.']);
        }

        return $this->envelope->receipt($metadata, $secret);
    }
}
