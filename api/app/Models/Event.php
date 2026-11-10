<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Event extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const IDENTITY_STANDARD = 'standard';

    public const IDENTITY_CHECK = 'id_check';

    protected $fillable = [
        'organizer_id',
        'venue_id',
        'title',
        'slug',
        'description',
        'capacity',
        'registration_opens_at',
        'registration_closes_at',
        'registration_closed_at',
        'starts_at',
        'ends_at',
        're_entry_allowed',
        'identity_verification_level',
        'ticket_transfers_allowed',
        'status',
        'banner_path',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'registration_closed_at' => 'datetime',
            'snapshot_version' => 'integer',
            'snapshot_prepared_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            're_entry_allowed' => 'boolean',
            'ticket_transfers_allowed' => 'boolean',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(EventStaff::class);
    }

    public function managerAssignments(): HasMany
    {
        return $this->hasMany(EventManagerAssignment::class);
    }

    public function responsibilityAudits(): HasMany
    {
        return $this->hasMany(EventResponsibilityAudit::class);
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_staff')
            ->withPivot('access_point_id')
            ->withTimestamps();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function ticketTransfers(): HasMany
    {
        return $this->hasMany(TicketTransfer::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRegistrationOpen(): bool
    {
        if (! $this->isPublished() || $this->registration_closed_at !== null || $this->snapshot_prepared_at !== null) {
            return false;
        }

        $now = Carbon::now();

        return $now->betweenIncluded($this->registration_opens_at, $this->registration_closes_at)
            && $now->lte($this->ends_at);
    }

    public function canTransferTickets(): bool
    {
        return $this->isPublished() && $this->ticket_transfers_allowed && $this->snapshot_prepared_at === null
            && $this->ends_at !== null && Carbon::now()->lt($this->ends_at);
    }

    public function assertSnapshotNotPrepared(): void
    {
        if ($this->snapshot_prepared_at !== null) {
            throw ValidationException::withMessages([
                'event' => 'This event has a prepared snapshot and cannot be changed.',
            ]);
        }
    }

    public function getConfirmedRegistrationsCount(): int
    {
        return $this->registrations()->where('status', 'confirmed')->count();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', Carbon::now());
    }
}
