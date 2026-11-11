<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSimulatedPaymentAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'actor_user_id',
        'enabled',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
