<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['student_id', 'name', 'institutional_email', 'college', 'activated_user_id'])]
class StudentIdentity extends Model
{
    use HasFactory;

    /**
     * The user account activated from this institutional identity, if any.
     */
    public function activatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_user_id');
    }

    /**
     * Challenges issued for activating this identity.
     */
    public function activationChallenges(): HasMany
    {
        return $this->hasMany(StudentActivationChallenge::class, 'student_identity_id');
    }

    /**
     * Latest challenge issued for activating this identity.
     */
    public function latestChallenge(): HasOne
    {
        return $this->hasOne(StudentActivationChallenge::class, 'student_identity_id')->latestOfMany();
    }

    /**
     * Whether this institutional identity has already been claimed by a user account.
     */
    public function isActivated(): bool
    {
        return $this->activated_user_id !== null;
    }

    /**
     * Find identity by student ID or institutional email.
     */
    public static function findByIdentifier(string $identifier): ?self
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        return static::where(function (Builder $query) use ($trimmed) {
            $query->whereRaw('LOWER(student_id) = ?', [Str::lower($trimmed)])
                ->orWhereRaw('LOWER(institutional_email) = ?', [Str::lower($trimmed)]);
        })->first();
    }

    /**
     * Masked institutional email for display in challenges (e.g. "a2***@student.mseuf.edu.ph").
     */
    public function getMaskedEmailAttribute(): string
    {
        $email = (string) $this->institutional_email;
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (strlen($local) <= 2) {
            $maskedLocal = substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = substr($local, 0, 2) . str_repeat('*', min(4, max(1, strlen($local) - 2)));
        }

        return $maskedLocal . '@' . $domain;
    }
}
