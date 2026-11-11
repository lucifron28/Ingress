<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TicketCredentialRotation
{
    public const REDEMPTION_RECOVERY_MINUTES = 15;

    public function issueGuestRedemptionCode(Ticket $ticket, int $eventId): string
    {
        $code = bin2hex(random_bytes(32));
        $ticket->forceFill(['redemption_hash' => hash('sha256', $code)])->save();
        $this->recordGuestRotation($eventId, $ticket, 0, (int) $ticket->credential_version, 'issued', null);

        return $code;
    }

    public function rotateAdmissionCredential(Ticket $ticket, ?string $redemptionCode = null): void
    {
        $secret = bin2hex(random_bytes(32));
        $ticket->forceFill([
            'qr_hash' => hash('sha256', $secret),
            'encrypted_secret' => Crypt::encryptString($secret),
            'credential_version' => ((int) $ticket->credential_version) + 1,
            'issued_at' => now(),
            'redemption_hash' => $redemptionCode === null ? null : hash('sha256', $redemptionCode),
        ])->save();
    }

    public function rotateGuestCredentials(
        Event $event,
        Ticket $ticket,
        string $kind,
        string $recoverySessionHash,
        ?int $actorUserId = null,
    ): string {
        if (! in_array($kind, ['redeemed', 'recovery'], true)) {
            throw new \InvalidArgumentException('Guest ticket credentials can only be rotated for a redemption or recovery.');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $recoverySessionHash)) {
            throw new \InvalidArgumentException('A valid recovery session hash is required for a guest ticket rotation.');
        }

        $fromVersion = (int) $ticket->credential_version;
        $previousRedemptionHash = $kind === 'redeemed' ? $ticket->redemption_hash : null;
        $code = bin2hex(random_bytes(32));
        $this->rotateAdmissionCredential($ticket, $code);
        $this->recordGuestRotation(
            $event->id,
            $ticket,
            $fromVersion,
            (int) $ticket->credential_version,
            $kind,
            $actorUserId,
            $previousRedemptionHash,
            $code,
            $recoverySessionHash,
        );

        return $code;
    }

    public function recoverInterruptedGuestRotation(
        Ticket $ticket,
        string $kind,
        string $recoverySessionHash,
        ?int $actorUserId = null,
        ?string $previousRedemptionHash = null,
    ): ?string {
        if (! in_array($kind, ['redeemed', 'recovery'], true)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $recoverySessionHash)
            || ! is_string($ticket->redemption_hash)) {
            return null;
        }

        if (($kind === 'redeemed'
                && ($actorUserId !== null
                    || ! is_string($previousRedemptionHash)
                    || ! preg_match('/\A[a-f0-9]{64}\z/', $previousRedemptionHash)))
            || ($kind === 'recovery' && ($actorUserId === null || $previousRedemptionHash !== null))) {
            return null;
        }

        $query = DB::table('guest_ticket_credential_rotations')
            ->where('ticket_id', $ticket->id)
            ->where('to_credential_version', (int) $ticket->credential_version)
            ->where('kind', $kind)
            ->where('recovery_session_hash', $recoverySessionHash)
            ->where('redemption_code_expires_at', '>', now())
            ->whereNotNull('redemption_code_ciphertext');

        if ($kind === 'redeemed') {
            $query->whereNull('actor_user_id')
                ->where('previous_redemption_hash', $previousRedemptionHash);
        } else {
            $query->where('actor_user_id', $actorUserId)
                ->whereNull('previous_redemption_hash');
        }

        $rotation = $query->lockForUpdate()->first(['redemption_code_ciphertext']);

        if (! $rotation || ! is_string($rotation->redemption_code_ciphertext)) {
            return null;
        }

        try {
            $code = Crypt::decryptString($rotation->redemption_code_ciphertext);
        } catch (\Throwable) {
            return null;
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $code)
            || ! hash_equals($ticket->redemption_hash, hash('sha256', $code))) {
            return null;
        }

        return $code;
    }

    private function recordGuestRotation(
        int $eventId,
        Ticket $ticket,
        int $fromVersion,
        int $toVersion,
        string $kind,
        ?int $actorUserId,
        ?string $previousRedemptionHash = null,
        ?string $redemptionCode = null,
        ?string $recoverySessionHash = null,
    ): void {
        DB::table('guest_ticket_credential_rotations')
            ->where('ticket_id', $ticket->id)
            ->whereNotNull('redemption_code_ciphertext')
            ->update([
                'previous_redemption_hash' => null,
                'recovery_session_hash' => null,
                'redemption_code_ciphertext' => null,
                'redemption_code_expires_at' => null,
            ]);

        DB::table('guest_ticket_credential_rotations')->insert([
            'event_id' => $eventId,
            'ticket_id' => $ticket->id,
            'from_credential_version' => $fromVersion,
            'to_credential_version' => $toVersion,
            'kind' => $kind,
            'actor_user_id' => $actorUserId,
            'previous_redemption_hash' => $previousRedemptionHash,
            'recovery_session_hash' => $recoverySessionHash,
            'redemption_code_ciphertext' => $redemptionCode === null ? null : Crypt::encryptString($redemptionCode),
            'redemption_code_expires_at' => $redemptionCode === null
                ? null
                : now()->addMinutes(self::REDEMPTION_RECOVERY_MINUTES),
            'created_at' => now(),
        ]);
    }
}
