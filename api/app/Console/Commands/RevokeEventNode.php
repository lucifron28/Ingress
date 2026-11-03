<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokeEventNode extends Command
{
    protected $signature = 'ingress:revoke-node {node_id}';

    protected $description = 'Revoke an Event Node transfer credential';

    public function handle(BindRuntimeDatabase $runtime): int
    {
        $nodeId = $this->argument('node_id');
        if (config('ingress.mode') !== 'cloud' || ! is_string($nodeId) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nodeId)) {
            $this->error('Use a valid node ID on the cloud runtime.');

            return self::FAILURE;
        }

        $runtime->execute();
        $found = DB::transaction(function () use ($nodeId) {
            $node = DB::table('event_nodes')->where('id', $nodeId)->lockForUpdate()->first();
            if (! $node) {
                return false;
            }
            if ($node->revoked_at === null) {
                DB::table('event_nodes')->where('id', $nodeId)->update([
                    'key_ciphertext' => null, 'revoked_at' => now(), 'updated_at' => now(),
                ]);
            }

            return true;
        });
        if (! $found) {
            $this->error('Node not found.');

            return self::FAILURE;
        }
        $this->info('Node transfer credential revoked.');

        return self::SUCCESS;
    }
}
