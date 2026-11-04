<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Ticket extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'registration_id',
        'public_id',
        'qr_hash',
        'encrypted_secret',
        'status',
        'credential_version',
        'issued_at',
        'revoked_at',
    ];

    protected $hidden = [
        'encrypted_secret',
    ];

    protected function casts(): array
    {
        return [
            'credential_version' => 'integer',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getQrPayload(): ?string
    {
        if (! $this->encrypted_secret) {
            return null;
        }

        try {
            $secret = Crypt::decryptString($this->encrypted_secret);

            if (! preg_match('/\A[a-fA-F0-9]{64}\z/', $secret) || ! $this->verifySecret($secret)) {
                return null;
            }

            return "ingress:v1:{$this->public_id}:{$secret}";
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifySecret(string $scannedSecret): bool
    {
        return hash_equals($this->qr_hash, hash('sha256', $scannedSecret));
    }
}
