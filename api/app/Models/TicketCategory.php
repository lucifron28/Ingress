<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketCategory extends Model
{
    public const ELIGIBILITY_PUBLIC = 'public';

    public const ELIGIBILITY_STUDENT = 'student';

    protected $fillable = [
        'event_id',
        'name',
        'price_minor',
        'currency',
        'capacity',
        'eligibility',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function checkoutOrders(): HasMany
    {
        return $this->hasMany(CheckoutOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function remainingCapacity(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        $confirmed = $this->confirmed_registrations_count
            ?? $this->registrations()->where('status', Registration::STATUS_CONFIRMED)->count();
        $reserved = $this->active_checkout_orders_count
            ?? CheckoutOrder::query()->where('ticket_category_id', $this->id)->activeReservation()->count();

        return max(0, $this->capacity - $confirmed - $reserved);
    }
}
