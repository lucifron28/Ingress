<?php

namespace App\Console\Commands;

use App\Actions\ReceiveSnapshot;
use Illuminate\Console\Command;

class ReceiveEventSnapshot extends Command
{
    protected $signature = 'ingress:receive-snapshot {path}';

    protected $description = 'Verify and stage an encrypted snapshot on an Event Node';

    public function handle(ReceiveSnapshot $receiver): int
    {
        $path = $this->argument('path');
        if (! is_string($path) || ! is_file($path)) {
            $this->error('Snapshot file is missing or too large.');

            return self::FAILURE;
        }
        $contents = file_get_contents($path, false, null, 0, 16 * 1024 * 1024 + 1);
        if ($contents === false || strlen($contents) > 16 * 1024 * 1024) {
            $this->error('Snapshot file could not be read or is too large.');

            return self::FAILURE;
        }
        $receipt = $receiver->execute($contents);
        $this->line('Snapshot staged. Return this receipt to the cloud organizer:');
        $this->line($receipt);

        return self::SUCCESS;
    }
}
