<?php

namespace App\Actions;

use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AdmitTicket
{
    /**
     * Executes the 11-step server-authoritative admission pipeline.
     *
     * @return array{
     *     http_code: int,
     *     status: string,
     *     message: string,
     *     detail: string,
     *     admission?: array<string, mixed>,
     *     previous_admission?: array<string, mixed>,
     *     ticket?: array<string, mixed>
     * }
     */
    public function execute(
        User $staff,
        int $eventId,
        int $accessPointId,
        string $qrPayload,
        string $clientScanId
    ): array {
        // Check 1 & 2: Event existence and Staff authorization
        $event = Event::find($eventId);
        if (! $event) {
            return [
                'http_code' => 404,
                'status' => 'not_found',
                'message' => 'Event Not Found',
                'detail' => 'The requested event does not exist.',
            ];
        }

        if ($event->isCancelled()) {
            return [
                'http_code' => 422,
                'status' => 'event_cancelled',
                'message' => 'Event Cancelled',
                'detail' => 'This event has been cancelled and cannot admit attendees.',
            ];
        }

        if ($event->status === Event::STATUS_COMPLETED) {
            return [
                'http_code' => 422,
                'status' => 'event_completed',
                'message' => 'Event Completed',
                'detail' => 'This event has already concluded.',
            ];
        }

        // Admission closes when the event ends. `starts_at` is deliberately not
        // enforced: doors may open before the scheduled start.
        if (Carbon::now()->gt($event->ends_at)) {
            return [
                'http_code' => 422,
                'status' => 'event_completed',
                'message' => 'Event Ended',
                'detail' => 'Admission closed at '.$event->ends_at->format('g:i A').'.',
            ];
        }

        $isSuperUser = $staff->isAdmin() || $event->organizer_id === $staff->id;
        $staffAssignment = EventStaff::where('event_id', $event->id)->where('user_id', $staff->id)->first();

        if (! $isSuperUser && ! $staffAssignment) {
            return [
                'http_code' => 403,
                'status' => 'unauthorized_staff',
                'message' => 'Unauthorized Staff',
                'detail' => 'You are not assigned to operate entrance scanners for this event.',
            ];
        }

        // Check 3: Access Point validation
        $accessPoint = AccessPoint::where('event_id', $event->id)
            ->where('id', $accessPointId)
            ->where('is_active', true)
            ->first();

        if (! $accessPoint) {
            return [
                'http_code' => 422,
                'status' => 'invalid_gate',
                'message' => 'Invalid Gate',
                'detail' => 'The selected access point is invalid or currently inactive.',
            ];
        }

        // Gate Assignment Enforcement: Staff bound to a specific gate cannot operate a different gate
        if (! $isSuperUser && $staffAssignment && $staffAssignment->access_point_id !== null) {
            if ((int) $staffAssignment->access_point_id !== (int) $accessPoint->id) {
                $assignedGate = AccessPoint::find($staffAssignment->access_point_id);
                $assignedName = $assignedGate?->name ?? 'another gate';

                return [
                    'http_code' => 403,
                    'status' => 'gate_mismatch',
                    'message' => 'Unauthorized Access Point',
                    'detail' => "You are assigned to scan at {$assignedName}. You cannot operate scanners at {$accessPoint->name}.",
                ];
            }
        }

        // Check 4: QR Payload format verification
        $cleanedPayload = trim($qrPayload);
        if (! preg_match('/^ingress:v1:([A-Za-z0-9\-]+):([A-Fa-f0-9]{64})$/', $cleanedPayload, $matches)) {
            return [
                'http_code' => 422,
                'status' => 'invalid_ticket',
                'message' => 'Invalid Ticket',
                'detail' => 'This ticket could not be verified (Malformed QR format).',
            ];
        }

        $publicId = $matches[1];
        $scannedSecret = $matches[2];

        // Check 5: Ticket existence
        $ticket = Ticket::with(['registration.user', 'registration.event.venue'])
            ->where('public_id', $publicId)
            ->first();

        if (! $ticket) {
            return [
                'http_code' => 422,
                'status' => 'invalid_ticket',
                'message' => 'Invalid Ticket',
                'detail' => 'This ticket could not be verified.',
            ];
        }

        // Check 6: Cryptographic secret verification (constant-time)
        if (! $ticket->verifySecret($scannedSecret)) {
            return [
                'http_code' => 422,
                'status' => 'invalid_ticket',
                'message' => 'Invalid Ticket',
                'detail' => 'Cryptographic credential mismatch.',
            ];
        }

        // Check 7: Registration status
        if (! $ticket->registration || $ticket->registration->status !== Registration::STATUS_CONFIRMED) {
            return [
                'http_code' => 422,
                'status' => 'revoked_ticket',
                'message' => 'Revoked Ticket',
                'detail' => 'Attendee registration was cancelled or revoked.',
            ];
        }

        // Check 8: Ticket revocation state
        if (! $ticket->isActive()) {
            return [
                'http_code' => 422,
                'status' => 'revoked_ticket',
                'message' => 'Revoked Ticket',
                'detail' => 'Ticket is revoked and not eligible for admission.',
            ];
        }

        // Check 9: Event ownership match
        if ($ticket->registration->event_id !== $event->id) {
            $ticketEventTitle = $ticket->registration->event->title ?? 'Another Event';

            return [
                'http_code' => 422,
                'status' => 'wrong_event',
                'message' => 'Wrong Event',
                'detail' => "This ticket was issued for: {$ticketEventTitle}.",
            ];
        }

        // Check 10: Client scan idempotency (handling duplicate network submissions)
        $existingById = Admission::with(['accessPoint', 'scannedBy'])
            ->where('client_scan_id', $clientScanId)
            ->first();

        if ($existingById) {
            $isMatchingTicket = (int) $existingById->ticket_id === (int) $ticket->id;
            $isMatchingGate = (int) $existingById->access_point_id === (int) $accessPoint->id;
            $isMatchingEvent = (int) $existingById->accessPoint?->event_id === (int) $event->id;

            if (! $isMatchingTicket || ! $isMatchingGate || ! $isMatchingEvent) {
                return [
                    'http_code' => 409,
                    'status' => 'idempotency_conflict',
                    'message' => 'Idempotency Key Conflict',
                    'detail' => 'The client scan ID was already used for a different admission request.',
                ];
            }

            $formattedTime = $existingById->admitted_at->format('g:i A');

            return [
                'http_code' => 200,
                'status' => 'admitted',
                'message' => 'Ticket Valid',
                'detail' => "Admitted at {$formattedTime}",
                'admission' => [
                    'id' => $existingById->id,
                    'admitted_at' => $existingById->admitted_at->toISOString(),
                    'access_point' => $existingById->accessPoint->name,
                    'scanned_by' => $existingById->scannedBy?->name ?? 'Staff',
                ],
                'ticket' => [
                    'public_id' => $ticket->public_id,
                    'attendee_name' => $ticket->registration->user->name,
                    'student_id' => $ticket->registration->user->student_id,
                ],
            ];
        }

        // Check 11: Anti-Passback / Duplicate Admission Protection (Transactional with Ticket Row Lock)
        try {
            return DB::transaction(function () use ($staff, $ticket, $accessPoint, $event, $clientScanId, $scannedSecret) {
                // Revalidate the authoritative event state under a FOR SHARE lock. A
                // concurrent cancellation or completion must not be able to commit
                // between this check and the admission insert. FOR SHARE is the
                // least-contended correct choice: shared locks are mutually
                // compatible, so scanners for different tickets still run in
                // parallel, while any event writer (organizer cancel/update, which
                // takes FOR UPDATE) is forced to wait.
                //
                // Lock order is events -> tickets on every path (RegisterForEvent and
                // OrganizerEventController::update both take the events row first), so
                // no deadlock cycle can form.
                $lockedEvent = Event::whereKey($event->getKey())->sharedLock()->first();

                if (! $lockedEvent || $lockedEvent->isCancelled()) {
                    return [
                        'http_code' => 422,
                        'status' => 'event_cancelled',
                        'message' => 'Event Cancelled',
                        'detail' => 'This event has been cancelled and cannot admit attendees.',
                    ];
                }

                if ($lockedEvent->status === Event::STATUS_COMPLETED || Carbon::now()->gt($lockedEvent->ends_at)) {
                    return [
                        'http_code' => 422,
                        'status' => 'event_completed',
                        'message' => 'Event Completed',
                        'detail' => 'This event is no longer admitting attendees.',
                    ];
                }

                // Lock authoritative ticket row to serialize concurrent gate scans
                $lockedTicket = Ticket::where('id', $ticket->id)->lockForUpdate()->first();

                // Re-validate credential state under the lock. A concurrent cancellation or
                // credential rotation must not slip through between the pre-checks and the insert.
                if (! $lockedTicket
                    || ! $lockedTicket->verifySecret($scannedSecret)
                    || ! $lockedTicket->isActive()
                    || ! $lockedTicket->registration
                    || $lockedTicket->registration->status !== Registration::STATUS_CONFIRMED
                    || (int) $lockedTicket->registration->event_id !== (int) $event->id) {
                    return [
                        'http_code' => 422,
                        'status' => 'invalid_ticket',
                        'message' => 'Invalid Ticket',
                        'detail' => 'This ticket could not be verified.',
                    ];
                }

                // Re-verify idempotency under lock in case of concurrent identical submissions
                $existingUnderLock = Admission::with(['accessPoint', 'scannedBy'])
                    ->where('client_scan_id', $clientScanId)
                    ->first();

                if ($existingUnderLock) {
                    $isMatchingTicket = (int) $existingUnderLock->ticket_id === (int) $lockedTicket->id;
                    $isMatchingGate = (int) $existingUnderLock->access_point_id === (int) $accessPoint->id;
                    $isMatchingEvent = (int) $existingUnderLock->accessPoint?->event_id === (int) $event->id;

                    if (! $isMatchingTicket || ! $isMatchingGate || ! $isMatchingEvent) {
                        return [
                            'http_code' => 409,
                            'status' => 'idempotency_conflict',
                            'message' => 'Idempotency Key Conflict',
                            'detail' => 'The client scan ID was already used for a different admission request.',
                        ];
                    }

                    $formattedTime = $existingUnderLock->admitted_at->format('g:i A');

                    return [
                        'http_code' => 200,
                        'status' => 'admitted',
                        'message' => 'Ticket Valid',
                        'detail' => "Admitted at {$formattedTime}",
                        'admission' => [
                            'id' => $existingUnderLock->id,
                            'admitted_at' => $existingUnderLock->admitted_at->toISOString(),
                            'access_point' => $existingUnderLock->accessPoint->name,
                            'scanned_by' => $existingUnderLock->scannedBy?->name ?? 'Staff',
                        ],
                        'ticket' => [
                            'public_id' => $lockedTicket->public_id,
                            'attendee_name' => $lockedTicket->registration->user->name,
                            'student_id' => $lockedTicket->registration->user->student_id,
                        ],
                    ];
                }

                // Anti-passback is scoped to the credential issuance that produced the
                // admission: history from a retired credential is retained but does not
                // block the credential issued after a cancellation/re-registration.
                $previousAdmission = Admission::with(['accessPoint', 'scannedBy'])
                    ->where('ticket_id', $lockedTicket->id)
                    ->where('credential_version', $lockedTicket->credential_version)
                    ->first();

                if ($previousAdmission) {
                    $gateName = $previousAdmission->accessPoint?->name ?? 'Another Gate';
                    $time = $previousAdmission->admitted_at->format('g:i A');

                    return [
                        'http_code' => 409,
                        'status' => 'already_admitted',
                        'message' => 'Already Checked In',
                        'detail' => "Previously admitted at {$gateName}, {$time}",
                        'previous_admission' => [
                            'admitted_at' => $previousAdmission->admitted_at->toISOString(),
                            'access_point' => $gateName,
                            'scanned_by' => $previousAdmission->scannedBy?->name ?? 'Staff',
                        ],
                        'ticket' => [
                            'public_id' => $lockedTicket->public_id,
                            'attendee_name' => $lockedTicket->registration->user->name,
                            'student_id' => $lockedTicket->registration->user->student_id,
                        ],
                    ];
                }

                // Create authoritative admission record
                $admission = Admission::create([
                    'ticket_id' => $lockedTicket->id,
                    'access_point_id' => $accessPoint->id,
                    'scanned_by_user_id' => $staff->id,
                    'client_scan_id' => $clientScanId,
                    'credential_version' => $lockedTicket->credential_version,
                    'admitted_at' => Carbon::now(),
                ]);

                $formattedTime = $admission->admitted_at->format('g:i A');

                return [
                    'http_code' => 200,
                    'status' => 'admitted',
                    'message' => 'Ticket Valid',
                    'detail' => "Admitted at {$formattedTime}",
                    'admission' => [
                        'id' => $admission->id,
                        'admitted_at' => $admission->admitted_at->toISOString(),
                        'access_point' => $accessPoint->name,
                        'scanned_by' => $staff->name,
                    ],
                    'ticket' => [
                        'public_id' => $lockedTicket->public_id,
                        'attendee_name' => $lockedTicket->registration->user->name,
                        'student_id' => $lockedTicket->registration->user->student_id,
                    ],
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests carried the same client_scan_id for different tickets and
            // were therefore not serialized by the ticket row lock. Only
            // admissions.client_scan_id is written inside this transaction, so the
            // violation identifies a replayed idempotency key.
            return [
                'http_code' => 409,
                'status' => 'idempotency_conflict',
                'message' => 'Idempotency Key Conflict',
                'detail' => 'The client scan ID was already used for a different admission request.',
            ];
        }
    }
}
