<?php

namespace App\Actions;

use Illuminate\Validation\ValidationException;
use JsonException;

class AuthorityGrant
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['transfer' => 'Invalid authority grant or signing key.']);
    }

    private function key(mixed $value, int $length): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if ($decoded === false || strlen($decoded) !== $length || ! hash_equals(base64_encode($decoded), $value)) {
            $this->invalid();
        }

        return $decoded;
    }

    public function sign(array $metadata): string
    {
        $key = $this->key(config('ingress.authority_private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $public = $this->key(config('ingress.authority_public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if (! hash_equals(sodium_crypto_sign_publickey_from_secretkey($key), $public)) {
            $this->invalid();
        }
        $payload = rtrim(strtr(base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = sodium_crypto_sign_detached('ingress authority grant v1.'.$payload, $key);
        if (! sodium_crypto_sign_verify_detached($signature, 'ingress authority grant v1.'.$payload, $public)) {
            $this->invalid();
        }

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    public function verify(string $grant): array
    {
        if (strlen($grant) > 4096 || ! preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $grant, $matches)) {
            $this->invalid();
        }
        $signature = base64_decode(strtr($matches[2], '-_', '+/'), true);
        $payload = base64_decode(strtr($matches[1], '-_', '+/'), true);
        $key = $this->key(config('ingress.authority_public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $payload === false
            || ! sodium_crypto_sign_verify_detached($signature, 'ingress authority grant v1.'.$matches[1], $key)) {
            $this->invalid();
        }
        try {
            $metadata = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        if (! is_array($metadata)) {
            $this->invalid();
        }

        return $metadata;
    }
}
