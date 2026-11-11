<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Tickets\TicketCredentialRotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class IssueRegistrationTicket
{
    public function __construct(private TicketCredentialRotation $credentialRotation) {}

    /**
     * @param  array{attendee_type: string, guest_name: ?string, guest_email: ?string}  $details
     * @return array{registration: Registration, ticket: Ticket, qr_payload: string, redemption_code: ?string}
     */
    public function execute(Event $event, ?TicketCategory $category, ?User $user, array $details): array
    {
        $secret = bin2hex(random_bytes(32));
        $registration = Registration::create([
            'event_id' => $event->id,
            'ticket_category_id' => $category?->id,
            'user_id' => $user?->id,
            'attendee_type' => $details['attendee_type'],
            'guest_name' => $details['guest_name'],
            'guest_email' => $details['guest_email'],
            'status' => Registration::STATUS_CONFIRMED,
        ]);

        $ticket = Ticket::create([
            'registration_id' => $registration->id,
            'public_id' => 'ING-'.strtoupper(Str::random(10)),
            'qr_hash' => hash('sha256', $secret),
            'encrypted_secret' => Crypt::encryptString($secret),
            'status' => Ticket::STATUS_ACTIVE,
            'credential_version' => 1,
            'issued_at' => Carbon::now(),
        ]);
        $redemptionCode = $user === null
            ? $this->credentialRotation->issueGuestRedemptionCode($ticket, $event->id)
            : null;

        return [
            'registration' => $registration,
            'ticket' => $ticket,
            'qr_payload' => "ingress:v1:{$ticket->public_id}:{$secret}",
            'redemption_code' => $redemptionCode,
        ];
    }
}
