<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class NodeAdmissionAccess
{
    public function __construct(private AuthorityGrant $grants) {}

    public function allows(User $staff, int $eventId, bool $lock = false): bool
    {
        if (config('ingress.mode') !== 'node') {
            return false;
        }
        $token = $staff->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }
        $runtimeQuery = DB::table('runtime_identity')->where('id', 1);
        $runtime = ($lock ? $runtimeQuery->sharedLock() : $runtimeQuery)->first();
        if (! $runtime || $runtime->mode !== 'node' || $runtime->identity !== config('ingress.node_id')) {
            return false;
        }
        $import = DB::table('node_event_imports')->where('event_id', $eventId)->first();
        if (! $import) {
            return false;
        }
        $headQuery = DB::table('received_event_heads')->where('event_id', $import->cloud_event_id);
        $head = ($lock ? $headQuery->sharedLock() : $headQuery)->first();
        $authority = DB::table('node_event_authorities')->where('event_id', $eventId)->first();
        if (! $head || ! $authority || (int) $head->version !== (int) $import->version
            || (int) $authority->cloud_event_id !== (int) $import->cloud_event_id
            || $authority->snapshot_id !== $import->snapshot_id || (int) $authority->version !== (int) $import->version
            || ! hash_equals($authority->sha256, $import->sha256)) {
            return false;
        }
        try {
            $metadata = $this->grants->verify($authority->grant);
        } catch (ValidationException) {
            return false;
        }
        if ($metadata !== ['protocol' => 1, 'node_id' => config('ingress.node_id'), 'snapshot_id' => $import->snapshot_id,
            'event_id' => (int) $import->cloud_event_id, 'version' => (int) $import->version, 'sha256' => $import->sha256]) {
            return false;
        }
        $tokenQuery = PersonalAccessToken::whereKey($token->id);
        $current = ($lock ? $tokenQuery->sharedLock() : $tokenQuery)->first();
        if (! $current || $current->tokenable_type !== $staff->getMorphClass()
            || (int) $current->tokenable_id !== (int) $staff->id || $current->abilities !== ['node:staff']
            || ! hash_equals($current->token, $token->token)
            || ! $current->expires_at || $current->expires_at->isPast()
            || (config('sanctum.expiration') && $current->created_at->lte(now()->subMinutes(config('sanctum.expiration'))))) {
            return false;
        }
        $credentialQuery = DB::table('node_staff_credentials')->where('user_id', $staff->id);

        return (bool) ($lock ? $credentialQuery->sharedLock() : $credentialQuery)->first();
    }
}
