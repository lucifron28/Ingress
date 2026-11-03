<?php

namespace App\Console\Commands;

use App\Actions\ManageNodeStaffCredential;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProvisionNodeStaff extends Command
{
    protected $signature = 'ingress:provision-node-staff {cloud_user_id}';

    protected $description = 'Issue or rotate a local credential for assigned node staff';

    public function handle(ManageNodeStaffCredential $credentials): int
    {
        $id = $this->argument('cloud_user_id');
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id) || (string) (int) $id !== $id) {
            $this->error('Provide a valid cloud user ID.');

            return self::FAILURE;
        }
        try {
            $secret = $credentials->provision((int) $id);
        } catch (ValidationException) {
            $this->error('Staff member is not assigned to a current imported event on this node.');

            return self::FAILURE;
        }
        $this->line('Give this credential to the staff member through a secure channel. It will not be shown again:');
        $this->line('NODE_STAFF_PASSWORD='.$secret);

        return self::SUCCESS;
    }
}
