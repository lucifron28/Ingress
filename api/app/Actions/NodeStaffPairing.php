<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NodeStaffPairing
{
    public function __construct(private BindRuntimeDatabase $runtime, private NodeStaffAccess $access) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['pairing' => 'Pairing is invalid, expired, or no longer available.']);
    }

    public function issue(int $cloudUserId): array
    {
        if (config('ingress.mode') !== 'node' || $cloudUserId < 1) {
            $this->invalid();
        }
        $this->runtime->execute();

        return DB::transaction(function () use ($cloudUserId) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $link = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $cloudUserId)->first();
            $user = $link ? User::whereKey($link->local_id)->first() : null;
            if (! $user || ! $this->access->assigned($user->id)
                || ! DB::table('node_staff_credentials')->where('user_id', $user->id)->exists()) {
                $this->invalid();
            }
            $token = bin2hex(random_bytes(32));
            $expires = now()->addMinutes(3)->startOfSecond();
            DB::table('node_staff_pairings')->where('user_id', $user->id)->delete();
            DB::table('node_staff_pairings')->where('expires_at', '<=', now())->delete();
            DB::table('node_staff_pairings')->insert([
                'token_hash' => hash('sha256', $token), 'user_id' => $user->id, 'expires_at' => $expires, 'created_at' => now(),
            ]);

            return ['token' => $token, 'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z'), 'user' => $user];
        });
    }

    public function redeem(string $token, string $nodeId, string $deviceName): array
    {
        if (config('ingress.mode') !== 'node' || $nodeId !== config('ingress.node_id') || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $this->invalid();
        }
        $this->runtime->execute();

        return DB::transaction(function () use ($token, $deviceName) {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $pairing = DB::table('node_staff_pairings')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $user = $pairing ? User::whereKey($pairing->user_id)->first() : null;
            if (! $pairing || ! $user || now()->gte($pairing->expires_at)
                || ! $this->access->assigned($user->id)
                || ! DB::table('node_staff_credentials')->where('user_id', $user->id)->exists()) {
                $this->invalid();
            }
            DB::table('node_staff_pairings')->where('token_hash', $pairing->token_hash)->delete();
            $session = $user->createToken($deviceName, ['node:staff'], now()->addHours(12))->plainTextToken;

            return ['user' => $user, 'token' => $session];
        });
    }
}
