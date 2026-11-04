<?php

namespace App\Actions;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateEventAuthority
{
    public function __construct(private BindRuntimeDatabase $runtime, private AuthorityGrant $grants) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['transfer' => 'Authority grant does not match the current node import.']);
    }

    public function execute(string $grant): int
    {
        $nodeId = config('ingress.node_id');
        if (config('ingress.mode') !== 'node' || ! is_string($nodeId)) {
            $this->invalid();
        }
        $this->runtime->execute();
        $metadata = $this->grants->verify($grant);
        if (($metadata['protocol'] ?? null) !== 1 || ($metadata['node_id'] ?? null) !== $nodeId
            || ! is_int($metadata['event_id'] ?? null) || $metadata['event_id'] < 1) {
            $this->invalid();
        }

        return DB::transaction(function () use ($grant, $metadata, $nodeId) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $head = DB::table('received_event_heads')->where('event_id', $metadata['event_id'])->lockForUpdate()->first();
            $import = DB::table('node_event_imports')->where('cloud_event_id', $metadata['event_id'])->first();
            $event = $import ? DB::table('events')->where('id', $import->event_id)->lockForUpdate()->first() : null;
            $snapshot = $import ? DB::table('received_snapshots')->where('id', $import->snapshot_id)->first() : null;
            $link = DB::table('node_import_ids')->where('kind', 'event')->where('cloud_id', $metadata['event_id'])->first();
            if (! $head || ! $import || ! $event || ! $snapshot || ! $link
                || (int) $link->local_id !== (int) $event->id || (int) $snapshot->event_id !== (int) $import->cloud_event_id
                || (int) $snapshot->version !== (int) $import->version || ! in_array((int) $snapshot->schema_version, [1, 2, 3], true)
                || ! hash_equals($snapshot->sha256, $import->sha256)
                || ! hash_equals($snapshot->sha256, hash('sha256', Crypt::decryptString($snapshot->payload_ciphertext)))
                || $event->status !== 'published' || $event->snapshot_prepared_at === null
                || (int) $head->version !== (int) $import->version || (int) $event->snapshot_version !== (int) $import->version) {
                $this->invalid();
            }
            $expected = ['protocol' => 1, 'node_id' => $nodeId, 'snapshot_id' => $import->snapshot_id,
                'event_id' => (int) $import->cloud_event_id, 'version' => (int) $import->version, 'sha256' => $import->sha256];
            if ($metadata !== $expected) {
                $this->invalid();
            }
            $existing = DB::table('node_event_authorities')->where('cloud_event_id', $import->cloud_event_id)->first();
            if ($existing) {
                if ((int) $existing->event_id !== (int) $event->id || ! hash_equals($existing->grant, $grant)) {
                    $this->invalid();
                }

                return (int) $event->id;
            }
            $ticketAdmission = DB::table('admissions')->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
                ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
                ->where('registrations.event_id', $event->id)->exists();
            $gateAdmission = DB::table('admissions')->join('access_points', 'access_points.id', '=', 'admissions.access_point_id')
                ->where('access_points.event_id', $event->id)->exists();
            if ($ticketAdmission || $gateAdmission) {
                $this->invalid();
            }
            DB::table('node_event_authorities')->insert([
                'cloud_event_id' => $import->cloud_event_id, 'event_id' => $event->id, 'snapshot_id' => $import->snapshot_id,
                'version' => $import->version, 'sha256' => $import->sha256, 'grant' => $grant, 'activated_at' => now(),
            ]);

            return (int) $event->id;
        });
    }
}
