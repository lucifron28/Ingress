<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'student_id'])]
#[Hidden(['password', 'remember_token', 'firebase_uid'])]
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
     * `firebase_uid` links an institutional student to a server-verified Firebase
     * identity. A verified email proves mailbox ownership, not current enrollment;
     * `student_id` remains optional until an enrollment authority is integrated.
     */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_ORGANIZER = 'organizer';

    public const ROLE_STAFF = 'staff';

    public const ROLE_STUDENT = 'student';

    public const ROLE_EVENT_MANAGER = 'event_manager';

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

    public function managerAssignments(): HasMany
    {
        return $this->hasMany(EventManagerAssignment::class);
    }

    public function managedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_manager_assignments')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    public function managesEvent(Event $event): bool
    {
        return $this->isAdmin()
            || (int) $event->organizer_id === (int) $this->id
            || $this->managerAssignments()->where('event_id', $event->getKey())->exists();
    }

    public function hasEventManagementAccess(): bool
    {
        return $this->isAdmin()
            || $this->isOrganizer()
            || $this->managerAssignments()->exists()
            || $this->events()->exists();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    /**
     * Institutional identity linked to this user account, if student.
     */
    public function studentIdentity(): HasOne
    {
        return $this->hasOne(StudentIdentity::class, 'activated_user_id');
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
