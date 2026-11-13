<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StudentEmailEligibility
{
    public const INSTITUTIONAL_DOMAIN = 'student.mseuf.edu.ph';

    public function domain(): string
    {
        return self::INSTITUTIONAL_DOMAIN;
    }

    public function requiresInstitutionalEmail(): bool
    {
        $value = DB::table('student_access_settings')->where('id', 1)->value('require_institutional_email');
        abort_unless($value !== null, 503, 'Student access policy is unavailable.');

        return (bool) $value;
    }

    public function policyEnabledAt(): ?CarbonImmutable
    {
        $setting = DB::table('student_access_settings')->where('id', 1)->first(['policy_enabled_at']);
        abort_unless($setting, 503, 'Student access policy is unavailable.');
        $value = $setting->policy_enabled_at;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function isValidEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isInstitutionalEmail(string $email): bool
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if (! $this->isValidEmail($normalizedEmail)) {
            return false;
        }

        $separator = strrpos($normalizedEmail, '@');

        return $separator !== false
            && substr($normalizedEmail, $separator + 1) === self::INSTITUTIONAL_DOMAIN;
    }

    /**
     * Existing verified student accounts keep access after the administrator enables the restriction.
     * New accounts and registrations must use the institutional domain while it is enabled.
     */
    public function canResolveFirebaseIdentity(string $uid, string $email): bool
    {
        if ($uid === '' || ! $this->isValidEmail($email)) {
            return false;
        }

        if (! $this->requiresInstitutionalEmail() || $this->isInstitutionalEmail($email)) {
            return true;
        }

        $enabledAt = $this->policyEnabledAt();
        if ($enabledAt === null) {
            return false;
        }

        $existingUsers = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('created_at', '<=', $enabledAt)
            ->where(function ($query) use ($uid, $email): void {
                $query->where('firebase_uid', $uid)
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
            })
            ->limit(2)
            ->get(['firebase_uid']);

        if ($existingUsers->count() !== 1) {
            return false;
        }

        $linkedUid = $existingUsers->first()->firebase_uid;

        return $linkedUid === null || hash_equals($linkedUid, $uid);
    }

    public function canAccess(User $user): bool
    {
        if (! $this->hasVerifiedFirebaseIdentity($user)) {
            return false;
        }

        if (! $this->requiresInstitutionalEmail() || $this->isInstitutionalEmail($user->email)) {
            return true;
        }

        $enabledAt = $this->policyEnabledAt();

        return $enabledAt !== null
            && $user->created_at !== null
            && $user->created_at->lessThanOrEqualTo($enabledAt);
    }

    public function canRegister(User $user): bool
    {
        return $this->registrationError($user) === null;
    }

    public function registrationError(User $user): ?string
    {
        if (! $this->hasVerifiedFirebaseIdentity($user)) {
            return 'Only verified student accounts can register.';
        }

        if ($this->requiresInstitutionalEmail() && ! $this->isInstitutionalEmail($user->email)) {
            return 'New student registrations require an @'.self::INSTITUTIONAL_DOMAIN.' email address.';
        }

        return null;
    }

    public function lockPolicyForUpdate(): void
    {
        $setting = DB::table('student_access_settings')->where('id', 1)->lockForUpdate()->first();
        abort_unless($setting, 503, 'Student access policy is unavailable.');
    }

    public function hasVerifiedFirebaseIdentity(User $user): bool
    {
        return $this->isVerifiedStudentAccount($user);
    }

    public function isVerifiedInstitutionalStudent(?User $user): bool
    {
        return $user instanceof User
            && $this->isVerifiedStudentAccount($user)
            && $this->isInstitutionalEmail($user->email);
    }

    private function isVerifiedStudentAccount(User $user): bool
    {
        return $user->isStudent()
            && is_string($user->firebase_uid)
            && trim($user->firebase_uid) !== ''
            && $user->email_verified_at !== null
            && $this->isValidEmail($user->email);
    }
}
