<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecoverNodeWalkIn
{
    public function execute(User $staff, int $eventId, string $email): Ticket
    {
        $email = Str::lower(trim($email));
        if (config('ingress.mode') !== 'node' || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['walk_in' => 'Provide a valid attendee email.']);
        }

        return DB::transaction(function () use ($staff, $eventId, $email) {
            if (! app(NodeAdmissionAccess::class)->allows($staff, $eventId, true)) {
                abort(403, 'Node authority or staff session is unavailable.');
            }
            $event = Event::whereKey($eventId)->sharedLock()->firstOrFail();
            if (! EventStaff::where('event_id', $eventId)->where('user_id', $staff->id)->lockForUpdate()->exists()) {
                abort(403, 'You are not assigned to this event.');
            }
            if (! $event->isPublished()) {
                throw ValidationException::withMessages(['walk_in' => 'This event is not available for pass recovery.']);
            }
            $issuance = DB::table('node_walk_in_issuances as w')
                ->join('registrations as r', 'r.id', '=', 'w.registration_id')
                ->where('w.event_id', $eventId)->where('r.event_id', $eventId)->where('r.attendee_type', 'walk_in')
                ->where('r.status', Registration::STATUS_CONFIRMED)->where('r.guest_email', $email)
                ->first(['w.id', 'w.ticket_id', 'w.registration_id']);
            $ticket = $issuance ? Ticket::whereKey($issuance->ticket_id)->sharedLock()->first() : null;
            if (! $ticket || (int) $ticket->registration_id !== (int) $issuance->registration_id || ! $ticket->isActive()
                || $ticket->getQrPayload() === null) {
                throw ValidationException::withMessages(['walk_in' => 'No active walk-in pass was found for this event and email.']);
            }
            DB::table('node_walk_in_recoveries')->insert([
                'issuance_id' => $issuance->id, 'recovered_by_user_id' => $staff->id, 'recovered_at' => now(),
            ]);

            return $ticket;
        });
    }
}
