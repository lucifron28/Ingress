<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Registration extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_id',
        'user_id',
        'attendee_type',
        'guest_name',
        'guest_email',
        'guest_student_id',
        'walk_in_kind',
        'status',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public function attendeeName(): string
    {
        return $this->user_id !== null ? ($this->user?->name ?? 'Attendee') : ($this->guest_name ?? 'Attendee');
    }

    public function attendeeEmail(): ?string
    {
        return $this->user_id !== null ? $this->user?->email : $this->guest_email;
    }

    public function attendeeStudentId(): ?string
    {
        return $this->user_id !== null ? $this->user?->student_id : $this->guest_student_id;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }
}
