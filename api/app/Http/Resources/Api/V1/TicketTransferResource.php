<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $userId = (int) $request->user()->id;
        $event = $this->event;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'direction' => (int) $this->recipient_user_id === $userId ? 'incoming' : 'outgoing',
            'requested_at' => $this->requested_at?->toISOString(),
            'sender' => [
                'name' => $this->from_name,
                'email' => $this->from_email,
            ],
            'recipient' => [
                'name' => $this->recipient_name,
                'email' => $this->recipient_email,
            ],
            'ticket' => [
                'id' => $this->ticket_id,
                'public_id' => $this->ticket_public_id,
                'event' => [
                    'id' => $event?->id,
                    'title' => $event?->title,
                    'starts_at' => $event?->starts_at?->toISOString(),
                ],
            ],
        ];
    }
}
