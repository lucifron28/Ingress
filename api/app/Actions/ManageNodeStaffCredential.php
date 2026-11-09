<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ManageNodeStaffCredential
{
    public function __construct(private BindRuntimeDatabase $runtime, private NodeStaffAccess $access) {}

    private function invalid(): never
    {
        throw ValidationException::withMessages(['staff' => 'Staff member is not available on this node.']);
    }

    private function run(
        int $cloudUserId,
        ?string $hash,
        ?int $eventId = null,
        bool $onlyIfMissing = false,
        bool $onlyIfPresent = false,
    ): bool {
        if (config('ingress.mode') !== 'node' || $cloudUserId < 1) {
            $this->invalid();
        }
        $this->runtime->execute();

        return DB::transaction(function () use ($cloudUserId, $hash, $eventId, $onlyIfMissing, $onlyIfPresent): bool {
            DB::table('runtime_identity')->where('id', 1)->lockForUpdate()->first();
            $link = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $cloudUserId)->first();
            $user = $link ? User::whereKey($link->local_id)->lockForUpdate()->first() : null;
            $isAssigned = $user && ($eventId === null
                ? $this->access->assigned($user->id)
                : $this->access->assignedToEvent($user->id, $eventId));
            if (! $user || ($hash !== null && ! $isAssigned) || ($eventId !== null && ! $isAssigned)) {
                $this->invalid();
            }

            $hasCredential = DB::table('node_staff_credentials')->where('user_id', $user->id)->exists();
            if (($onlyIfMissing && $hasCredential) || ($onlyIfPresent && ! $hasCredential)) {
                return false;
            }
            if ($hash === null) {
                DB::table('node_staff_credentials')->where('user_id', $user->id)->delete();
            } else {
                DB::table('node_staff_credentials')->updateOrInsert(['user_id' => $user->id],
                    fn (bool $exists) => ['password_hash' => $hash, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
            }
            DB::table('node_staff_pairings')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            return true;
        });
    }

    public function provision(int $cloudUserId, ?int $eventId = null): string
    {
        $secret = bin2hex(random_bytes(16));
        $this->run($cloudUserId, Hash::make($secret), $eventId);

        return $secret;
    }

    public function provisionIfMissing(int $cloudUserId, ?int $eventId = null): ?string
    {
        $secret = bin2hex(random_bytes(16));

        return $this->run($cloudUserId, Hash::make($secret), $eventId, onlyIfMissing: true) ? $secret : null;
    }

    public function rotateIfPresent(int $cloudUserId, ?int $eventId = null): ?string
    {
        $secret = bin2hex(random_bytes(16));

        return $this->run($cloudUserId, Hash::make($secret), $eventId, onlyIfPresent: true) ? $secret : null;
    }

    public function revoke(int $cloudUserId, ?int $eventId = null): void
    {
        $this->run($cloudUserId, null, $eventId);
    }
}
