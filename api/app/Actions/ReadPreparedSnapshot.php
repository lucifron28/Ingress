<?php

namespace App\Actions;

use App\Models\Admission;
use App\Models\Event;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReadPreparedSnapshot
{
    /** @return array{record: object, json: string} */
    public function execute(Event $event): array
    {
        if (Admission::whereHas('ticket.registration', fn ($query) => $query->where('event_id', $event->id))->exists()) {
            throw ValidationException::withMessages(['event' => 'Events with existing admissions cannot be transferred.']);
        }
        $record = DB::table('event_snapshots')->where('event_id', $event->id)
            ->where('version', $event->snapshot_version)->whereNull('invalidated_at')->first();
        if ($event->snapshot_prepared_at === null || ! $record) {
            throw new RuntimeException('The prepared snapshot is missing or corrupt.');
        }
        $json = Crypt::decryptString($record->payload_ciphertext);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($record->sha256, hash('sha256', $json))
            || ($payload['snapshot_id'] ?? null) !== $record->id
            || ($payload['version'] ?? null) !== (int) $record->version
            || ($payload['schema_version'] ?? null) !== (int) $record->schema_version
            || ($payload['event']['id'] ?? null) !== $event->id) {
            throw new RuntimeException('The prepared snapshot is missing or corrupt.');
        }

        return ['record' => $record, 'json' => $json];
    }
}
