<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FirebaseIdentityVerifier
{
    public function isConfigured(): bool
    {
        return is_string(config('services.firebase.api_key'))
            && trim((string) config('services.firebase.api_key')) !== ''
            && is_string(config('services.firebase.project_id'))
            && trim((string) config('services.firebase.project_id')) !== '';
    }

    /**
     * @return array{uid: string, email: string, name: ?string}|null
     */
    public function verifyIdToken(string $idToken): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->post(config('services.firebase.identity_lookup_url').'?key='.urlencode(config('services.firebase.api_key')), [
                    'idToken' => $idToken,
                ]);
        } catch (Throwable) {
            throw new FirebaseIdentityServiceUnavailable;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new FirebaseIdentityServiceUnavailable;
        }

        if (! $response->successful()) {
            $firebaseError = $response->json('error.message');
            $firebaseErrorCode = is_string($firebaseError)
                && preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $firebaseError) === 1
                ? $firebaseError
                : 'UNCLASSIFIED';

            Log::warning('Firebase student identity lookup was rejected.', [
                'http_status' => $response->status(),
                'firebase_error_code' => $firebaseErrorCode,
            ]);

            return null;
        }

        $users = $response->json('users');
        if (! is_array($users) || count($users) !== 1 || ! is_array($users[0])) {
            Log::warning('Firebase student identity lookup returned an unexpected account record.', [
                'reason' => 'user_record_missing_or_ambiguous',
            ]);

            return null;
        }

        $firebaseUser = $users[0];
        $uid = $firebaseUser['localId'] ?? null;
        $email = $firebaseUser['email'] ?? null;
        $providers = $firebaseUser['providerUserInfo'] ?? [];
        $isPasswordAccount = is_array($providers)
            && collect($providers)->contains(fn (mixed $provider): bool => is_array($provider) && ($provider['providerId'] ?? null) === 'password');

        $rejectionReasons = [];
        if (! is_string($uid) || $uid === '' || strlen($uid) > 128) {
            $rejectionReasons[] = 'invalid_uid';
        }
        if (! is_string($email) || $email === '' || strlen($email) > 255) {
            $rejectionReasons[] = 'invalid_email';
        }
        if (($firebaseUser['emailVerified'] ?? false) !== true) {
            $rejectionReasons[] = 'email_not_verified';
        }
        if (($firebaseUser['disabled'] ?? null) !== false) {
            $rejectionReasons[] = 'account_disabled_or_status_missing';
        }
        if (! $isPasswordAccount) {
            $rejectionReasons[] = 'email_password_provider_missing';
        }

        if ($rejectionReasons !== []) {
            Log::warning('Firebase student identity lookup returned an ineligible account.', [
                'reasons' => $rejectionReasons,
            ]);

            return null;
        }

        $displayName = $firebaseUser['displayName'] ?? null;

        return [
            'uid' => $uid,
            'email' => mb_strtolower(trim($email)),
            'name' => is_string($displayName) && trim($displayName) !== '' ? mb_substr(trim($displayName), 0, 255) : null,
        ];
    }
}
