<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EventStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_event_staff' => EventStaff::where('user_id', $this->id)->exists(),
            'student_id' => $this->student_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
