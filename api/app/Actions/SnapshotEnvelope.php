<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;
use JsonException;

class SnapshotEnvelope
{
    public function __construct(private ValidateSnapshotPayload $validator) {}

    private function key(string $secret, string $purpose): string
    {
        $raw = base64_decode($secret, true);
        if ($raw === false || strlen($raw) !== 32 || ! hash_equals(base64_encode($raw), $secret)) {
            throw ValidationException::withMessages(['transfer' => 'Invalid node transfer credential.']);
        }

        return hash_hkdf('sha256', $raw, 32, $purpose);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['transfer' => 'Invalid snapshot transfer.']);
    }

    /** @return array{node_id: string, snapshot_id: string, event_id: int, version: int, sha256: string} */
    private function metadata(string $nodeId, string $json): array
    {
        $payload = $this->validator->execute($json);

        return ['node_id' => $nodeId, 'snapshot_id' => $payload['snapshot_id'],
            'event_id' => $payload['event']['id'], 'version' => $payload['version'], 'sha256' => hash('sha256', $json)];
    }

    public function seal(string $nodeId, string $secret, string $json): string
    {
        $metadata = $this->metadata($nodeId, $json);
        $encrypter = new Encrypter($this->key($secret, 'ingress snapshot encryption v1'), 'AES-256-CBC');

        $sealed = $encrypter->encryptString(json_encode(['protocol' => 1, 'metadata' => $metadata, 'payload' => $json], JSON_THROW_ON_ERROR));
        if (strlen($sealed) > 16 * 1024 * 1024) {
            throw ValidationException::withMessages(['transfer' => 'Snapshot package is too large.']);
        }

        return $sealed;
    }

    /** @return array{metadata: array, json: string} */
    public function open(string $nodeId, string $secret, string $envelope): array
    {
        if (strlen($envelope) > 16 * 1024 * 1024) {
            $this->invalid();
        }
        $decoded = base64_decode($envelope, true);
        if ($decoded === false || ! hash_equals(base64_encode($decoded), $envelope)) {
            $this->invalid();
        }
        $encrypter = new Encrypter($this->key($secret, 'ingress snapshot encryption v1'), 'AES-256-CBC');
        try {
            $body = json_decode($encrypter->decryptString($envelope), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->invalid();
        }
        if (! is_array($body) || ($body['protocol'] ?? null) !== 1 || ! is_string($body['payload'] ?? null)
            || ! is_array($body['metadata'] ?? null)) {
            $this->invalid();
        }
        $metadata = $this->metadata($nodeId, $body['payload']);
        if ($body['metadata'] !== $metadata) {
            $this->invalid();
        }

        return ['metadata' => $metadata, 'json' => $body['payload']];
    }

    public function receipt(array $metadata, string $secret): string
    {
        return $this->signReceipt($metadata, $secret, 'ingress snapshot receipt v1');
    }

    public function importReceipt(array $metadata, string $secret): string
    {
        return $this->signReceipt($metadata, $secret, 'ingress snapshot import receipt v1');
    }

    private function signReceipt(array $metadata, string $secret, string $purpose): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($metadata, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $mac = hash_hmac('sha256', $encoded, $this->key($secret, $purpose));

        return $encoded.'.'.$mac;
    }

    public function verifyReceipt(string $receipt, string $secret): array
    {
        return $this->verifySignedReceipt($receipt, $secret, 'ingress snapshot receipt v1');
    }

    public function verifyImportReceipt(string $receipt, string $secret): array
    {
        return $this->verifySignedReceipt($receipt, $secret, 'ingress snapshot import receipt v1');
    }

    private function verifySignedReceipt(string $receipt, string $secret, string $purpose): array
    {
        if (strlen($receipt) > 4096 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $receipt, $matches)) {
            $this->invalid();
        }
        $expected = hash_hmac('sha256', $matches[1], $this->key($secret, $purpose));
        if (! hash_equals($expected, $matches[2])) {
            $this->invalid();
        }
        $json = base64_decode(strtr($matches[1], '-_', '+/'), true);
        try {
            $metadata = json_decode($json === false ? '' : $json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        if (! is_array($metadata)) {
            $this->invalid();
        }

        return $metadata;
    }
}
