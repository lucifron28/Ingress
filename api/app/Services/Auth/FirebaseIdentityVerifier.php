<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
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
            return null;
        }

        $users = $response->json('users');
        if (! is_array($users) || count($users) !== 1 || ! is_array($users[0])) {
            return null;
        }

        $firebaseUser = $users[0];
        $uid = $firebaseUser['localId'] ?? null;
        $email = $firebaseUser['email'] ?? null;
        $providers = $firebaseUser['providerUserInfo'] ?? [];
        $isPasswordAccount = is_array($providers)
            && collect($providers)->contains(fn (mixed $provider): bool => is_array($provider) && ($provider['providerId'] ?? null) === 'password');

        if (! is_string($uid) || $uid === '' || strlen($uid) > 128
            || ! is_string($email) || $email === '' || strlen($email) > 255
            || ($firebaseUser['emailVerified'] ?? false) !== true
            || ($firebaseUser['disabled'] ?? null) !== false
            || ! $isPasswordAccount) {
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
