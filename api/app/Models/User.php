<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'student_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Coarse portal flag, not the full responsibility model.
     *
     * `role` decides which portal a user lands in (organizer console vs mobile app)
     * and is checked by `EnsureOrganizer` / `EnsureStaff`. Event-scoped
     * responsibility lives in the `event_staff` table instead, because the same
     * person can be event staff for one event while remaining an ordinary attendee
     * for every other. Authorization decisions for scanning are therefore
     * assignment-based (see `AdmitTicket`), never `role`-based.
     *
     * Identity (student / employee / alumni / guest) is intentionally not modelled
     * here yet; see `docs/architecture.md` §7.
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_STAFF = 'staff';
    public const ROLE_STUDENT = 'student';

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isOrganizer(): bool
    {
        return $this->role === self::ROLE_ORGANIZER || $this->isAdmin();
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF || $this->isAdmin();
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
