<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;

class AdmissionSyncEnvelope
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['sync' => 'Invalid admission synchronization package.']);
    }

    private function key(string $secret, string $purpose): string
    {
        $raw = base64_decode($secret, true);
        if ($raw === false || strlen($raw) !== 32 || ! hash_equals(base64_encode($raw), $secret)) {
            $this->invalid();
        }

        return hash_hkdf('sha256', $raw, 32, $purpose);
    }

    public function seal(string $payload, string $secret): string
    {
        $encrypter = new Encrypter($this->key($secret, 'ingress admission sync encryption v1'), 'AES-256-CBC');

        return $encrypter->encryptString($payload);
    }

    public function open(string $package, string $secret): string
    {
        if (strlen($package) > 262144 || ($decoded = base64_decode($package, true)) === false
            || ! hash_equals(base64_encode($decoded), $package)) {
            $this->invalid();
        }
        try {
            return (new Encrypter($this->key($secret, 'ingress admission sync encryption v1'), 'AES-256-CBC'))->decryptString($package);
        } catch (DecryptException) {
            $this->invalid();
        }
    }

    public function receipt(string $batchId, string $payloadHash, string $secret): string
    {
        return hash_hmac('sha256', $batchId.'.'.$payloadHash, $this->key($secret, 'ingress admission sync receipt v1'));
    }
}
