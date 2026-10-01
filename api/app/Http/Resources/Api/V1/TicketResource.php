<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registration = $this->registration;
        $event = $registration?->event;
        $attendee = $registration?->user;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'qr_payload' => $this->getQrPayload(),
            'event' => [
                'id' => $event?->id,
                'title' => $event?->title,
                'venue' => $event?->venue ? [
                    'id' => $event->venue->id,
                    'name' => $event->venue->name,
                    'location' => $event->venue->location,
                ] : null,
                'starts_at' => $event?->starts_at?->toISOString(),
                'ends_at' => $event?->ends_at?->toISOString(),
            ],
            'attendee' => [
                'name' => $attendee?->name,
                'student_id' => $attendee?->student_id,
                'email' => $attendee?->email,
            ],
        ];
    }
}
