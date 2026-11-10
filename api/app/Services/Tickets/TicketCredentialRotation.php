<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TicketCredentialRotation
{
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

    public function rotateGuestCredentials(Event $event, Ticket $ticket, string $kind, ?int $actorUserId = null): string
    {
        if (! in_array($kind, ['redeemed', 'recovery'], true)) {
            throw new \InvalidArgumentException('Guest ticket credentials can only be rotated for a redemption or recovery.');
        }

        $fromVersion = (int) $ticket->credential_version;
        $code = bin2hex(random_bytes(32));
        $this->rotateAdmissionCredential($ticket, $code);
        $this->recordGuestRotation($event->id, $ticket, $fromVersion, (int) $ticket->credential_version, $kind, $actorUserId);

        return $code;
    }

    private function recordGuestRotation(
        int $eventId,
        Ticket $ticket,
        int $fromVersion,
        int $toVersion,
        string $kind,
        ?int $actorUserId,
    ): void {
        DB::table('guest_ticket_credential_rotations')->insert([
            'event_id' => $eventId,
            'ticket_id' => $ticket->id,
            'from_credential_version' => $fromVersion,
            'to_credential_version' => $toVersion,
            'kind' => $kind,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }
}
