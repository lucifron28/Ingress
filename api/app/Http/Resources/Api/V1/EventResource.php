<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isUserRegistered = false;
        $userTicket = null;

        if ($user) {
            $registration = Registration::with('ticket')
                ->where('event_id', $this->id)
                ->where('user_id', $user->id)
                ->where('status', Registration::STATUS_CONFIRMED)
                ->first();

            if ($registration) {
                $isUserRegistered = true;
                $userTicket = $registration->ticket ? [
                    'id' => $registration->ticket->id,
                    'public_id' => $registration->ticket->public_id,
                    'status' => $registration->ticket->status,
                ] : null;
            }
        }

        $confirmedCount = $this->registrations()->where('status', 'confirmed')->count();
        $remaining = max(0, $this->capacity - $confirmedCount);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'capacity' => $this->capacity,
            'confirmed_registrations_count' => $confirmedCount,
            'remaining_capacity' => $remaining,
            'is_registration_open' => $this->isRegistrationOpen(),
            'registration_opens_at' => $this->registration_opens_at?->toISOString(),
            'registration_closes_at' => $this->registration_closes_at?->toISOString(),
            'registration_closed_at' => $this->registration_closed_at?->toISOString(),
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'status' => $this->status,
            'is_user_registered' => $isUserRegistered,
            'user_ticket' => $userTicket,
        ];
    }
}
