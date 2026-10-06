<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Admission extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'access_point_id',
        'scanned_by_user_id',
        'client_scan_id',
        'credential_version',
        'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'credential_version' => 'integer',
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
     * Admissions produced by the ticket's current credential issuance.
     *
     * Scoped to the ticket's active credential version so admission queries
     * track current validity while preserving historical admissions for audit
     * across credential rotation lifecycles (such as controlled reissue).
     */
    public function scopeForCurrentCredential(Builder $query): Builder
    {
        return $query->whereHas(
            'ticket',
            fn (Builder $ticket) => $ticket->whereColumn('tickets.credential_version', 'admissions.credential_version')
        );
    }
}
