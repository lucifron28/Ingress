<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventResponsibilityAudit extends Model
{
    public const ACTION_MANAGER_ASSIGNED = 'manager_assigned';

    public const ACTION_MANAGER_REVOKED = 'manager_revoked';

    public const ACTION_MANAGER_INVITED = 'manager_invited';

    public const ACTION_MANAGER_INVITATION_ACCEPTED = 'manager_invitation_accepted';

    public const ACTION_SCANNER_ASSIGNED = 'scanner_assigned';

    public const ACTION_SCANNER_GATE_CHANGED = 'scanner_gate_changed';

    public const ACTION_SCANNER_REVOKED = 'scanner_revoked';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event_title',
        'subject_user_id',
        'subject_email',
        'actor_user_id',
        'actor_email',
        'action',
        'access_point_id',
        'access_point_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }
}
