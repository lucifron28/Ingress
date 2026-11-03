<?php

namespace App\Console\Commands;

use App\Actions\ManageNodeStaffCredential;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RevokeNodeStaff extends Command
{
    protected $signature = 'ingress:revoke-node-staff {cloud_user_id}';

    protected $description = 'Revoke a local staff credential and its sessions';

    public function handle(ManageNodeStaffCredential $credentials): int
    {
        $id = $this->argument('cloud_user_id');
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id) || (string) (int) $id !== $id) {
            $this->error('Provide a valid cloud user ID.');

            return self::FAILURE;
        }
        try {
            $credentials->revoke((int) $id);
        } catch (ValidationException) {
            $this->error('Staff member is not available on this node.');

            return self::FAILURE;
        }
        $this->info('Local staff credential and sessions revoked.');

        return self::SUCCESS;
    }
}
