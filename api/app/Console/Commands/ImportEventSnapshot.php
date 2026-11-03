<?php

namespace App\Console\Commands;

use App\Actions\ImportReceivedSnapshot;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportEventSnapshot extends Command
{
    protected $signature = 'ingress:import-snapshot {event_id}';

    protected $description = 'Import or refresh the current staged cloud event without enabling admission';

    public function handle(ImportReceivedSnapshot $import): int
    {
        $id = $this->argument('event_id');
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id) || (string) (int) $id !== $id) {
            $this->error('Provide a valid cloud event ID.');

            return self::FAILURE;
        }
        try {
            $localId = $import->execute((int) $id);
        } catch (ValidationException) {
            $this->error('Snapshot import failed. Check the staged version and node data.');

            return self::FAILURE;
        }
        $this->info("Imported cloud event {$id} as node event {$localId}. Admission remains disabled.");

        return self::SUCCESS;
    }
}
