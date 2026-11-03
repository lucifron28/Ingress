<?php

namespace App\Console\Commands;

use App\Actions\ActivateEventAuthority;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ActivateEventNode extends Command
{
    protected $signature = 'ingress:activate-event {path}';

    protected $description = 'Activate a cloud authority grant on this Event Node';

    public function handle(ActivateEventAuthority $action): int
    {
        $path = $this->argument('path');
        if (! is_string($path) || ! is_file($path)) {
            $this->error('Grant file is missing.');

            return self::FAILURE;
        }
        $grant = file_get_contents($path, false, null, 0, 4097);
        if ($grant === false || strlen($grant) > 4096) {
            $this->error('Grant file could not be read or is too large.');

            return self::FAILURE;
        }
        try {
            $eventId = $action->execute(trim($grant));
        } catch (ValidationException) {
            $this->error('Authority activation failed. Check the grant and imported snapshot.');

            return self::FAILURE;
        }
        $this->info("Authority recorded for node event {$eventId}. Assigned staff can scan while connected to this node.");

        return self::SUCCESS;
    }
}
