<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['redemption_code_ciphertext'])]
class CheckoutOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const ACTIVE_RESERVATION_STATUSES = [self::STATUS_PENDING, self::STATUS_FAILED];

    protected $fillable = [
        'public_id',
        'event_id',
        'ticket_category_id',
        'user_id',
        'registration_id',
        'idempotency_key',
        'attendee_type',
        'guest_name',
        'guest_email',
        'amount_minor',
        'currency',
        'status',
        'reservation_expires_at',
        'redemption_code_ciphertext',
        'redemption_code_expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'reservation_expires_at' => 'immutable_datetime',
            'redemption_code_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function scopeActiveReservation(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_RESERVATION_STATUSES)
            ->where('reservation_expires_at', '>', now());
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketCategory(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
