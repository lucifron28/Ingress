<?php

namespace App\Actions;

use App\Models\StudentActivationChallenge;
use App\Models\StudentIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfirmStudentActivation
{
    /**
     * Verify activation challenge and create / activate the student user account.
     *
     * @return array{
     *     user: User,
     *     identity: StudentIdentity,
     *     token: string,
     *     token_type: string
     * }
     */
    public function execute(
        string $identifier,
        string $code,
        string $password,
        ?string $deviceName = null,
        ?string $ipAddress = null
    ): array {
        $trimmed = trim($identifier);

        // Locate student identity
        $identity = StudentIdentity::findByIdentifier($trimmed);

        if (! $identity) {
            throw ValidationException::withMessages([
                'identifier' => ['Student record not found in institutional directory.'],
            ]);
        }

        if ($identity->isActivated()) {
            throw ValidationException::withMessages([
                'identifier' => ['This student account has already been activated. Please sign in.'],
            ]);
        }

        // Find the latest challenge
        $challenge = StudentActivationChallenge::where('student_identity_id', $identity->id)
            ->latest('id')
            ->first();

        if (! $challenge || $challenge->isVerified()) {
            throw ValidationException::withMessages([
                'code' => ['No active activation challenge found. Please request a new code.'],
            ]);
        }

        if ($challenge->isExpired()) {
            throw ValidationException::withMessages([
                'code' => ['The activation code has expired. Please request a new code.'],
            ]);
        }

        if ($challenge->hasExceededAttempts()) {
            throw ValidationException::withMessages([
                'code' => ['Too many incorrect attempts. Please request a new code.'],
            ]);
        }

        // Validate code. Increment attempts outside the transaction so failed attempts persist.
        if (! $challenge->verifyCode($code)) {
            $challenge->increment('attempts');
            $remaining = StudentActivationChallenge::MAX_ATTEMPTS - $challenge->attempts;

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'code' => ['Invalid code. Maximum attempts reached. Please request a new code.'],
                ]);
            }

            throw ValidationException::withMessages([
                'code' => ["Invalid activation code. {$remaining} attempts remaining."],
            ]);
        }

        // Code is verified. Execute the activation inside an atomic transaction with row locks.
        return DB::transaction(function () use ($identity, $challenge, $password, $deviceName) {
            $lockedIdentity = StudentIdentity::where('id', $identity->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedIdentity || $lockedIdentity->isActivated()) {
                throw ValidationException::withMessages([
                    'identifier' => ['This student account has already been activated. Please sign in.'],
                ]);
            }

            $lockedChallenge = StudentActivationChallenge::where('id', $challenge->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedChallenge || $lockedChallenge->isVerified()) {
                throw ValidationException::withMessages([
                    'code' => ['This activation challenge has already been used.'],
                ]);
            }

            // Mark challenge verified
            $lockedChallenge->update([
                'verified_at' => now(),
            ]);

            // Locate existing user or create a new user account
            $normalizedEmail = Str::lower(trim($lockedIdentity->institutional_email));
            $user = User::where('email', $normalizedEmail)->first();

            if ($user) {
                $user->name = $lockedIdentity->name;
                $user->student_id = $lockedIdentity->student_id;
                $user->role = User::ROLE_STUDENT;
                $user->password = Hash::make($password);
                $user->save();
            } else {
                $user = User::create([
                    'name' => $lockedIdentity->name,
                    'email' => $normalizedEmail,
                    'student_id' => $lockedIdentity->student_id,
                    'role' => User::ROLE_STUDENT,
                    'password' => Hash::make($password),
                ]);
            }

            // Link identity to activated user
            $lockedIdentity->update([
                'activated_user_id' => $user->id,
            ]);

            // Mint Sanctum token for immediate mobile login
            $tokenDevice = $deviceName ?: 'student-mobile';
            $token = $user->createToken($tokenDevice)->plainTextToken;

            return [
                'user' => $user,
                'identity' => $lockedIdentity,
                'token' => $token,
                'token_type' => 'Bearer',
            ];
        });
    }
}
