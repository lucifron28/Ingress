<?php

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class NodeStaffAccess
{
    public function assignments(): Builder
    {
        return DB::table('event_staff')
            ->join('events', 'events.id', '=', 'event_staff.event_id')
            ->join('node_event_imports', 'node_event_imports.event_id', '=', 'events.id')
            ->join('received_event_heads', 'received_event_heads.event_id', '=', 'node_event_imports.cloud_event_id')
            ->where('events.status', 'published')
            ->whereColumn('node_event_imports.version', 'received_event_heads.version');
    }

    public function assigned(int $userId): bool
    {
        return $this->assignments()->where('event_staff.user_id', $userId)->exists();
    }
}
