<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RegisterForEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\Event;
use App\Services\Auth\StudentEmailEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    /**
     * Register authenticated attendee for an event.
     */
    public function store(Request $request, Event $event, RegisterForEvent $action, StudentEmailEligibility $studentEmailEligibility): JsonResponse
    {
        $user = $request->user();
        if (! $user->isStudent()) {
            return response()->json([
                'message' => 'Only students are permitted to self-register for events.',
            ], 403);
        }

        $result = DB::transaction(function () use ($studentEmailEligibility, $user, $action, $event): array|string {
            $studentEmailEligibility->lockPolicyForUpdate();
            $error = $studentEmailEligibility->registrationError($user);
            if ($error !== null) {
                return $error;
            }

            return $action->execute($user, $event->id);
        });

        if (is_string($result)) {
            return response()->json([
                'message' => $result,
            ], 403);
        }

        return response()->json([
            'message' => 'Registration confirmed and ticket issued.',
            'registration_id' => $result['registration']->id,
            'ticket' => new TicketResource($result['ticket']),
            'qr_payload' => $result['qr_payload'],
        ], 201);
    }
}
