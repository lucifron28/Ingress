<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_identity_id',
    'code_hash',
    'expires_at',
    'verified_at',
    'attempts',
    'ip_address',
    'user_agent',
])]
class StudentActivationChallenge extends Model
{
    use HasFactory;

    /**
     * Maximum incorrect verification attempts allowed before locking the challenge.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Challenge lifetime in minutes.
     */
    public const DEFAULT_EXPIRATION_MINUTES = 15;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Institutional student identity this challenge belongs to.
     */
    public function studentIdentity(): BelongsTo
    {
        return $this->belongsTo(StudentIdentity::class, 'student_identity_id');
    }

    /**
     * Determine whether this challenge has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Determine whether this challenge has been verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Determine whether this challenge is still pending and active.
     */
    public function isPending(): bool
    {
        return ! $this->isVerified() && ! $this->isExpired() && ! $this->hasExceededAttempts();
    }

    /**
     * Determine whether the maximum number of attempts has been exceeded.
     */
    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Verify the presented plaintext code against the stored hash in constant time.
     */
    public function verifyCode(string $code): bool
    {
        $candidateHash = hash('sha256', trim($code));

        return hash_equals($this->code_hash, $candidateHash);
    }
}
