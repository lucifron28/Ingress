<?php

namespace App\Support;

use App\Models\NodeOperatorAudit;
use LogicException;

class NodeOperatorAuditRecorder
{
    public function recordLoginSucceeded(int $operatorId): void
    {
        $this->record($operatorId, 'login.succeeded', 'succeeded');
    }

    public function recordLogoutSucceeded(int $operatorId): void
    {
        $this->record($operatorId, 'logout.succeeded', 'succeeded');
    }

    public function recordStaffAction(
        int $operatorId,
        string $action,
        int $affectedUserId,
        int $affectedEventId,
        string $outcome = 'succeeded',
    ): void {
        $actions = [
            'staff.pairing.generated',
            'staff.pairing.cancelled',
            'staff.credential.provisioned',
            'staff.credential.rotated',
            'staff.credential.revoked',
        ];
        $outcomes = ['succeeded', 'rejected', 'no_pending_code'];
        if (! in_array($action, $actions, true) || ! in_array($outcome, $outcomes, true)) {
            throw new LogicException('Unsupported node operator audit event.');
        }

        $this->record($operatorId, $action, $outcome, $affectedUserId, $affectedEventId);
    }

    public function recordEventAction(
        int $operatorId,
        string $action,
        ?int $affectedEventId,
        string $outcome = 'succeeded',
    ): void {
        $actions = [
            'snapshot.received',
            'snapshot.imported',
            'authority.activated',
        ];
        $outcomes = ['succeeded', 'rejected'];
        if (! in_array($action, $actions, true) || ! in_array($outcome, $outcomes, true)) {
            throw new LogicException('Unsupported node operator audit event.');
        }

        $this->record($operatorId, $action, $outcome, affectedEventId: $affectedEventId);
    }

    private function record(
        int $operatorId,
        string $action,
        string $outcome,
        ?int $affectedUserId = null,
        ?int $affectedEventId = null,
    ): void {
        NodeOperatorAudit::query()->create([
            'actor_id' => $operatorId,
            'action' => $action,
            'affected_user_id' => $affectedUserId,
            'affected_event_id' => $affectedEventId,
            'node_id' => config('ingress.node_id'),
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }
}
