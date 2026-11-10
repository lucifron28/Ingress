<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketTransferResource;
use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\Tickets\TicketCredentialRotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketTransferController extends Controller
{
    public function __construct(private TicketCredentialRotation $credentialRotation) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;
        $transfers = TicketTransfer::with('event')
            ->where('status', TicketTransfer::STATUS_PENDING)
            ->where(fn (Builder $query) => $query->where('from_user_id', $userId)->orWhere('recipient_user_id', $userId))
            ->orderByDesc('requested_at')
            ->get();

        return TicketTransferResource::collection($transfers);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'string', 'max:255'],
        ]);
        $sender = $request->user();

        $eventId = $ticket->registration()->value('event_id');
        if (! $eventId) {
            throw ValidationException::withMessages(['ticket' => 'This ticket cannot be transferred.']);
        }

        $transfer = DB::transaction(function () use ($eventId, $ticket, $sender, $validated) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $lockedTicket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $registration = Registration::whereKey($lockedTicket->registration_id)->lockForUpdate()->firstOrFail();

            $this->assertTransferAllowed($event);
            if ((int) $registration->user_id !== (int) $sender->id
                || ! $sender->isStudent()
                || ! $sender->email_verified_at
                || $registration->attendee_type !== 'student'
                || $registration->status !== Registration::STATUS_CONFIRMED
                || ! $lockedTicket->isActive()
                || Admission::where('ticket_id', $lockedTicket->id)->exists()) {
                throw ValidationException::withMessages(['ticket' => 'This ticket is not eligible for transfer.']);
            }

            $recipientIdentifier = Str::lower(trim($validated['recipient']));
            $recipient = User::query()->where(function (Builder $query) use ($recipientIdentifier) {
                $query->whereRaw('LOWER(email) = ?', [$recipientIdentifier])
                    ->orWhereRaw('LOWER(student_id) = ?', [$recipientIdentifier]);
            })->first();
            if (! $recipient || ! $recipient->isStudent() || ! $recipient->email_verified_at
                || (int) $recipient->id === (int) $sender->id) {
                throw ValidationException::withMessages(['recipient' => 'Choose a different verified student account.']);
            }

            $recipientEmail = Str::lower($recipient->email);
            $alreadyRegistered = Registration::where('event_id', $event->id)
                ->where(fn (Builder $query) => $query->where('user_id', $recipient->id)
                    ->orWhereRaw('LOWER(guest_email) = ?', [$recipientEmail]))
                ->exists();
            if ($alreadyRegistered) {
                throw ValidationException::withMessages(['recipient' => 'The recipient already has a registration for this event.']);
            }

            if (TicketTransfer::where('pending_ticket_id', $lockedTicket->id)->exists()) {
                throw ValidationException::withMessages(['ticket' => 'A transfer request is already pending for this ticket.']);
            }

            return TicketTransfer::create([
                'event_id' => $event->id,
                'ticket_id' => $lockedTicket->id,
                'ticket_public_id' => $lockedTicket->public_id,
                'pending_ticket_id' => $lockedTicket->id,
                'from_user_id' => $sender->id,
                'recipient_user_id' => $recipient->id,
                'from_name' => $sender->name,
                'from_email' => $sender->email,
                'recipient_name' => $recipient->name,
                'recipient_email' => $recipient->email,
                'status' => TicketTransfer::STATUS_PENDING,
                'requested_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Transfer request sent. You keep this ticket until the recipient accepts.',
            'transfer' => new TicketTransferResource($transfer->load('event')),
        ], 201);
    }

    public function accept(Request $request, TicketTransfer $ticketTransfer): JsonResponse
    {
        $this->resolve($request->user(), $ticketTransfer, 'accept');

        return response()->json(['message' => 'Ticket transfer accepted.']);
    }

    public function decline(Request $request, TicketTransfer $ticketTransfer): JsonResponse
    {
        $this->resolve($request->user(), $ticketTransfer, 'decline');

        return response()->json(['message' => 'Ticket transfer declined.']);
    }

    public function cancel(Request $request, TicketTransfer $ticketTransfer): JsonResponse
    {
        $this->resolve($request->user(), $ticketTransfer, 'cancel');

        return response()->json(['message' => 'Ticket transfer cancelled.']);
    }

    private function resolve(User $actor, TicketTransfer $transfer, string $decision): void
    {
        DB::transaction(function () use ($actor, $transfer, $decision) {
            $event = Event::whereKey($transfer->event_id)->lockForUpdate()->firstOrFail();
            $lockedTransfer = TicketTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($lockedTransfer->status !== TicketTransfer::STATUS_PENDING || $lockedTransfer->pending_ticket_id === null) {
                throw ValidationException::withMessages(['transfer' => 'This transfer request is no longer pending.']);
            }

            if ($decision === 'accept') {
                if ((int) $lockedTransfer->recipient_user_id !== (int) $actor->id) {
                    abort(403);
                }
                $this->assertTransferAllowed($event);

                $registration = Registration::whereHas('ticket', fn ($query) => $query->whereKey($lockedTransfer->pending_ticket_id))
                    ->lockForUpdate()->firstOrFail();
                $ticket = Ticket::whereKey($lockedTransfer->pending_ticket_id)->lockForUpdate()->firstOrFail();
                if ((int) $registration->user_id !== (int) $lockedTransfer->from_user_id
                    || (int) $registration->event_id !== (int) $event->id
                    || $registration->status !== Registration::STATUS_CONFIRMED
                    || ! $ticket->isActive()
                    || Admission::where('ticket_id', $ticket->id)->exists()) {
                    throw ValidationException::withMessages(['transfer' => 'This ticket is no longer eligible for transfer.']);
                }
                if (! $actor->isStudent() || ! $actor->email_verified_at) {
                    throw ValidationException::withMessages(['transfer' => 'Only verified student accounts can accept a ticket transfer.']);
                }

                $alreadyRegistered = Registration::where('event_id', $event->id)
                    ->where('id', '!=', $registration->id)
                    ->where(fn (Builder $query) => $query->where('user_id', $actor->id)
                        ->orWhereRaw('LOWER(guest_email) = ?', [Str::lower($actor->email)]))
                    ->exists();
                if ($alreadyRegistered) {
                    throw ValidationException::withMessages(['transfer' => 'You already have a registration for this event.']);
                }

                $registration->user_id = $actor->id;
                $registration->save();
                $this->credentialRotation->rotateAdmissionCredential($ticket);

                $lockedTransfer->status = TicketTransfer::STATUS_ACCEPTED;
                $lockedTransfer->accepted_at = now();
            } else {
                $participantId = $decision === 'decline'
                    ? $lockedTransfer->recipient_user_id
                    : $lockedTransfer->from_user_id;
                if ((int) $participantId !== (int) $actor->id) {
                    abort(403);
                }
                $lockedTransfer->status = $decision === 'decline'
                    ? TicketTransfer::STATUS_DECLINED
                    : TicketTransfer::STATUS_CANCELLED;
                $lockedTransfer->{$decision === 'decline' ? 'declined_at' : 'cancelled_at'} = now();
            }

            $lockedTransfer->pending_ticket_id = null;
            $lockedTransfer->save();
        });
    }

    private function assertTransferAllowed(Event $event): void
    {
        if (config('ingress.mode') === 'node') {
            throw ValidationException::withMessages(['transfer' => 'Ticket transfers are available only on the cloud.']);
        }
        if (! $event->canTransferTickets()) {
            throw ValidationException::withMessages(['transfer' => 'Ticket transfers are not available for this event.']);
        }
    }
}
