<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Registration;
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
        $user = $request->user();
        $registration = $this->registration;
        $event = $registration?->event;
        $attendee = $registration?->user;
        $isOwner = $user && $registration && (int) $registration->user_id === (int) $user->id;
        $canExposeQr = $isOwner
            && $this->isActive()
            && $event && ! $event->isCancelled()
            && $registration?->status === Registration::STATUS_CONFIRMED;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'qr_payload' => $this->when($canExposeQr, fn () => $this->getQrPayload()),
            'event' => [
                'id' => $event?->id,
                'title' => $event?->title,
                'status' => $event?->status,
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
