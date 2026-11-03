<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RegisterEventNode extends Command
{
    protected $signature = 'ingress:register-node {node_id}';

    protected $description = 'Create a transfer credential for an Event Node';

    public function handle(BindRuntimeDatabase $runtime): int
    {
        $nodeId = $this->argument('node_id');
        if (config('ingress.mode') !== 'cloud' || ! is_string($nodeId) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nodeId)) {
            $this->error('Use a valid node ID on the cloud runtime.');

            return self::FAILURE;
        }

        $runtime->execute();
        $secret = base64_encode(random_bytes(32));
        $inserted = DB::table('event_nodes')->insertOrIgnore([
            'id' => $nodeId, 'key_ciphertext' => Crypt::encryptString($secret),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted !== 1) {
            $this->error('That node ID is already registered or revoked.');

            return self::FAILURE;
        }

        $this->line('Transfer this secret securely to the node. It will not be shown again:');
        $this->line('INGRESS_NODE_SYNC_KEY='.$secret);

        return self::SUCCESS;
    }
}
