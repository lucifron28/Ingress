<?php

namespace App\Console\Commands;

use App\Actions\BindRuntimeDatabase;
use App\Actions\NodeStaffAccess;
use Illuminate\Console\Command;

class ListNodeStaff extends Command
{
    protected $signature = 'ingress:list-node-staff';

    protected $description = 'List staff assigned to current imported node events';

    public function handle(BindRuntimeDatabase $runtime, NodeStaffAccess $access): int
    {
        if (config('ingress.mode') !== 'node') {
            $this->error('Use this command on an Event Node.');

            return self::FAILURE;
        }
        $runtime->execute();
        $staff = $access->assignments()->join('users', 'users.id', '=', 'event_staff.user_id')
            ->join('node_import_ids', function ($join) {
                $join->on('node_import_ids.local_id', '=', 'users.id')->where('node_import_ids.kind', '=', 'user');
            })->select('node_import_ids.cloud_id', 'users.name', 'users.email')->distinct()->orderBy('node_import_ids.cloud_id')->get();
        $this->table(['Cloud user ID', 'Name', 'Email'], $staff->map(fn ($user) => [$user->cloud_id, $user->name, $user->email])->all());

        return self::SUCCESS;
    }
}
