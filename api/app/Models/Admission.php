<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Admission extends Model
{
    use HasFactory;

    public const DIRECTION_ENTRY = 'entry';

    public const DIRECTION_EXIT = 'exit';

    protected $fillable = [
        'ticket_id',
        'access_point_id',
        'scanned_by_user_id',
        'client_scan_id',
        'credential_version',
        'direction',
        'identity_check_confirmed',
        'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'credential_version' => 'integer',
            'identity_check_confirmed' => 'boolean',
            'admitted_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }

    /**
     * Scope query to admissions matching the ticket's active credential version.
     *
     * Retained for credential-specific analysis, audit queries, and controlled
     * reissue verification. Physical admission and attendee checked-in state
     * are tracked at the ticket level across all credential versions.
     */
    public function scopeForCurrentCredential(Builder $query): Builder
    {
        return $query->whereHas(
            'ticket',
            fn (Builder $ticket) => $ticket->whereColumn('tickets.credential_version', 'admissions.credential_version')
        );
    }
}
