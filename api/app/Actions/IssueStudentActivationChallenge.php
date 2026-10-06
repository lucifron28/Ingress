<?php

namespace App\Actions;

use App\Models\StudentActivationChallenge;
use App\Models\StudentIdentity;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class IssueStudentActivationChallenge
{
    /**
     * Issue a new student activation challenge.
     *
     * @return array{
     *     challenge: StudentActivationChallenge,
     *     code: string,
     *     masked_email: string,
     *     expires_at: \Illuminate\Support\Carbon
     * }
     */
    public function execute(
        StudentIdentity $identity,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        int $ttlMinutes = StudentActivationChallenge::DEFAULT_EXPIRATION_MINUTES
    ): array {
        if ($identity->isActivated()) {
            throw new InvalidArgumentException('This student identity has already been activated.');
        }

        // Expire any existing active challenges for this student identity
        StudentActivationChallenge::where('student_identity_id', $identity->id)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        // Generate a 6-digit cryptographically secure numeric OTP
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $challenge = StudentActivationChallenge::create([
            'student_identity_id' => $identity->id,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
        ]);

        Log::info("Student activation code issued for [{$identity->student_id}] ({$identity->institutional_email}): {$code}");

        return [
            'challenge' => $challenge,
            'code' => $code,
            'masked_email' => $identity->masked_email,
            'expires_at' => $expiresAt,
        ];
    }
}
