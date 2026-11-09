<?php

namespace App\Console\Commands;

use App\Support\CloudOperatorDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Throwable;

#[Signature('ingress:register-node {node_id}')]
#[Description('Create a one-time transfer credential for an Event Node on the verified cloud database.')]
class RegisterEventNode extends Command
{
    protected $description = 'Create a transfer credential for an Event Node';

    public function handle(CloudOperatorDatabase $cloudDatabase): int
    {
        $nodeId = $this->argument('node_id');
        if (config('ingress.mode') !== 'cloud' || ! is_string($nodeId) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nodeId)) {
            $this->error('Use a valid node ID on the cloud runtime.');

            return self::FAILURE;
        }

        $errors = $cloudDatabase->configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $connection = $cloudDatabase->connection();
        } catch (Throwable) {
            $this->error('Could not verify the cloud PostgreSQL 18 database and runtime identity. Confirm Render startup completed.');

            return self::FAILURE;
        }

        $secret = base64_encode(random_bytes(32));
        $inserted = 0;
        try {
            $locked = $cloudDatabase->runLocked($connection, function () use ($connection, $nodeId, $secret, &$inserted): void {
                $inserted = $connection->table('event_nodes')->insertOrIgnore([
                    'id' => $nodeId, 'key_ciphertext' => Crypt::encryptString($secret),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            $this->error('Event Node registration failed. Check the cloud database state and retry.');

            return self::FAILURE;
        }

        if (! $locked) {
            $this->error('The cloud database is busy with a deployment migration. Retry after startup completes.');

            return self::FAILURE;
        }

        if ($inserted !== 1) {
            $this->error('That node ID is already registered or revoked.');

            return self::FAILURE;
        }

        $this->line('Transfer this secret securely to the node. It will not be shown again:');
        $this->line('INGRESS_NODE_SYNC_KEY='.$secret);

        return self::SUCCESS;
    }
}
