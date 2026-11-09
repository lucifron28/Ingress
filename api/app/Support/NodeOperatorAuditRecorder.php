<?php

namespace App\Support;

use App\Models\NodeOperatorAudit;

class NodeOperatorAuditRecorder
{
    public function recordLoginSucceeded(int $operatorId): void
    {
        $this->record($operatorId, 'login.succeeded');
    }

    public function recordLogoutSucceeded(int $operatorId): void
    {
        $this->record($operatorId, 'logout.succeeded');
    }

    private function record(int $operatorId, string $action): void
    {
        NodeOperatorAudit::query()->create([
            'actor_id' => $operatorId,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
