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
     * Read-only validation of an attendee ticket before staff confirmation.
     *
     * Performs all non-mutating event, staff, gate, and credential checks, plus
     * anti-passback. Does NOT create an admission record or acquire locks.
     *
     * @return array{
     *     http_code: int,
     *     status: string,
     *     message: string,
     *     detail: string,
     *     previous_admission?: array<string, mixed>,
     *     ticket?: array<string, mixed>
     * }
     */
    public function preview(
        User $staff,
        int $eventId,
        int $accessPointId,
        string $qrPayload
    ): array {
        $context = $this->validateTicketContext($staff, $eventId, $accessPointId, $qrPayload);

        if (! $context['valid']) {
            return $context['error'];
        }

        $ticket = $context['ticket'];

        // Physical anti-passback is ticket-level: any prior admission marks the ticket already admitted.
        $previousAdmission = Admission::with(['accessPoint', 'scannedBy'])
            ->where('ticket_id', $ticket->id)
            ->latest('admitted_at')
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
                    'public_id' => $ticket->public_id,
                    'attendee_name' => $ticket->registration->user->name,
                    'student_id' => $ticket->registration->user->student_id,
                ],
            ];
        }

        return [
            'http_code' => 200,
            'status' => 'valid',
            'message' => 'Valid Ticket',
            'detail' => 'Verify the attendee identity before confirming admission.',
            'ticket' => [
                'public_id' => $ticket->public_id,
                'attendee_name' => $ticket->registration->user->name,
                'student_id' => $ticket->registration->user->student_id,
                'profile_photo_url' => null,
            ],
        ];
    }

    /**
     * Executes the authoritative admission pipeline upon staff confirmation.
     *
     * Revalidates everything inside a database transaction under lock,
     * enforces anti-passback, creates the admission record, and returns the result.
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
        $context = $this->validateTicketContext($staff, $eventId, $accessPointId, $qrPayload);

        if (! $context['valid']) {
            return $context['error'];
        }

        $event = $context['event'];
        $accessPoint = $context['access_point'];
        $ticket = $context['ticket'];
        $scannedSecret = $context['scanned_secret'];
        $existingById = Admission::with(['accessPoint', 'scannedBy'])
            ->where('client_scan_id', $clientScanId)
            ->first();

        if ($existingById) {
            $isMatchingTicket = (int) $existingById->ticket_id === (int) $ticket->id;
            $isMatchingGate = (int) $existingById->access_point_id === (int) $accessPoint->id;
            $isMatchingEvent = (int) $existingById->accessPoint?->event_id === (int) $event->id;
            // Retries must belong to the same credential issuance.
            $isMatchingCredential = (int) $existingById->credential_version === (int) $ticket->credential_version;

            if (! $isMatchingTicket || ! $isMatchingGate || ! $isMatchingEvent || ! $isMatchingCredential) {
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

        try {
            return DB::transaction(function () use ($staff, $ticket, $accessPoint, $event, $clientScanId, $scannedSecret, $accessPointId) {
                // Lock event before ticket. Shared event locks keep different-ticket scans
                // concurrent while forcing cancellation and event updates to wait.
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

                // Serialize scans that target the same ticket.
                $lockedTicket = Ticket::where('id', $ticket->id)->lockForUpdate()->first();

                // Recheck mutable credential state after acquiring the ticket lock.
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

                // Recheck idempotency after locking to cover concurrent retries.
                $existingUnderLock = Admission::with(['accessPoint', 'scannedBy'])
                    ->where('client_scan_id', $clientScanId)
                    ->first();

                if ($existingUnderLock) {
                    $isMatchingTicket = (int) $existingUnderLock->ticket_id === (int) $lockedTicket->id;
                    $isMatchingGate = (int) $existingUnderLock->access_point_id === (int) $accessPoint->id;
                    $isMatchingEvent = (int) $existingUnderLock->accessPoint?->event_id === (int) $event->id;
                    $isMatchingCredential = (int) $existingUnderLock->credential_version === (int) $lockedTicket->credential_version;

                    if (! $isMatchingTicket || ! $isMatchingGate || ! $isMatchingEvent || ! $isMatchingCredential) {
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

                // Physical anti-passback is ticket-level: rotating credentials never resets admission state.
                $previousAdmission = Admission::with(['accessPoint', 'scannedBy'])
                    ->where('ticket_id', $lockedTicket->id)
                    ->latest('admitted_at')
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

                // Re-verify staff authorization before creating admission
                $isSuperUser = $staff->isAdmin() || (int) $lockedEvent->organizer_id === (int) $staff->id;
                $staffAssignment = EventStaff::where('event_id', $lockedEvent->id)
                    ->where('user_id', $staff->id)
                    ->first();

                if (! $isSuperUser && ! $staffAssignment) {
                    return [
                        'http_code' => 403,
                        'status' => 'unauthorized_staff',
                        'message' => 'Unauthorized Staff',
                        'detail' => 'You are not assigned to operate entrance scanners for this event.',
                    ];
                }

                $reverifiedAccessPoint = AccessPoint::where('event_id', $lockedEvent->id)
                    ->where('id', $accessPointId)
                    ->where('is_active', true)
                    ->first();

                if (! $reverifiedAccessPoint) {
                    return [
                        'http_code' => 422,
                        'status' => 'invalid_gate',
                        'message' => 'Invalid Gate',
                        'detail' => 'The selected access point is invalid or currently inactive.',
                    ];
                }

                if (! $isSuperUser && $staffAssignment && $staffAssignment->access_point_id !== null) {
                    if ((int) $staffAssignment->access_point_id !== (int) $reverifiedAccessPoint->id) {
                        $assignedGate = AccessPoint::find($staffAssignment->access_point_id);
                        $assignedName = $assignedGate?->name ?? 'another gate';

                        return [
                            'http_code' => 403,
                            'status' => 'gate_mismatch',
                            'message' => 'Unauthorized Access Point',
                            'detail' => "You are assigned to scan at {$assignedName}. You cannot operate scanners at {$reverifiedAccessPoint->name}.",
                        ];
                    }
                }

                $admission = Admission::create([
                    'ticket_id' => $lockedTicket->id,
                    'access_point_id' => $reverifiedAccessPoint->id,
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
                        'access_point' => $reverifiedAccessPoint->name,
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
            // Different tickets are not serialized by the ticket lock; the unique
            // client_scan_id constraint resolves that cross-ticket race.
            return [
                'http_code' => 409,
                'status' => 'idempotency_conflict',
                'message' => 'Idempotency Key Conflict',
                'detail' => 'The client scan ID was already used for a different admission request.',
            ];
        }
    }

    /**
     * Shared read-only context validation for preview and execution.
     *
     * @return array{
     *     valid: bool,
     *     error?: array{http_code: int, status: string, message: string, detail: string},
     *     event?: Event,
     *     access_point?: AccessPoint,
     *     ticket?: Ticket,
     *     scanned_secret?: string
     * }
     */
    private function validateTicketContext(
        User $staff,
        int $eventId,
        int $accessPointId,
        string $qrPayload
    ): array {
        $event = Event::find($eventId);
        if (! $event) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 404,
                    'status' => 'not_found',
                    'message' => 'Event Not Found',
                    'detail' => 'The requested event does not exist.',
                ],
            ];
        }

        if ($event->isCancelled()) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'event_cancelled',
                    'message' => 'Event Cancelled',
                    'detail' => 'This event has been cancelled and cannot admit attendees.',
                ],
            ];
        }

        if ($event->status === Event::STATUS_COMPLETED) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'event_completed',
                    'message' => 'Event Completed',
                    'detail' => 'This event has already concluded.',
                ],
            ];
        }

        if (Carbon::now()->gt($event->ends_at)) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'event_completed',
                    'message' => 'Event Ended',
                    'detail' => 'Admission closed at '.$event->ends_at->format('g:i A').'.',
                ],
            ];
        }

        $isSuperUser = $staff->isAdmin() || $event->organizer_id === $staff->id;
        $staffAssignment = EventStaff::where('event_id', $event->id)->where('user_id', $staff->id)->first();

        if (! $isSuperUser && ! $staffAssignment) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 403,
                    'status' => 'unauthorized_staff',
                    'message' => 'Unauthorized Staff',
                    'detail' => 'You are not assigned to operate entrance scanners for this event.',
                ],
            ];
        }

        $accessPoint = AccessPoint::where('event_id', $event->id)
            ->where('id', $accessPointId)
            ->where('is_active', true)
            ->first();

        if (! $accessPoint) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'invalid_gate',
                    'message' => 'Invalid Gate',
                    'detail' => 'The selected access point is invalid or currently inactive.',
                ],
            ];
        }

        if (! $isSuperUser && $staffAssignment && $staffAssignment->access_point_id !== null) {
            if ((int) $staffAssignment->access_point_id !== (int) $accessPoint->id) {
                $assignedGate = AccessPoint::find($staffAssignment->access_point_id);
                $assignedName = $assignedGate?->name ?? 'another gate';

                return [
                    'valid' => false,
                    'error' => [
                        'http_code' => 403,
                        'status' => 'gate_mismatch',
                        'message' => 'Unauthorized Access Point',
                        'detail' => "You are assigned to scan at {$assignedName}. You cannot operate scanners at {$accessPoint->name}.",
                    ],
                ];
            }
        }

        $cleanedPayload = trim($qrPayload);
        if (! preg_match('/^ingress:v1:([A-Za-z0-9\-]+):([A-Fa-f0-9]{64})$/', $cleanedPayload, $matches)) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'invalid_ticket',
                    'message' => 'Invalid Ticket',
                    'detail' => 'This ticket could not be verified (Malformed QR format).',
                ],
            ];
        }

        $publicId = $matches[1];
        $scannedSecret = $matches[2];

        $ticket = Ticket::with(['registration.user', 'registration.event.venue'])
            ->where('public_id', $publicId)
            ->first();

        if (! $ticket) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'invalid_ticket',
                    'message' => 'Invalid Ticket',
                    'detail' => 'This ticket could not be verified.',
                ],
            ];
        }

        if (! $ticket->verifySecret($scannedSecret)) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'invalid_ticket',
                    'message' => 'Invalid Ticket',
                    'detail' => 'Cryptographic credential mismatch.',
                ],
            ];
        }

        if (! $ticket->registration || $ticket->registration->status !== Registration::STATUS_CONFIRMED) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'revoked_ticket',
                    'message' => 'Revoked Ticket',
                    'detail' => 'Attendee registration is not confirmed or ticket is revoked.',
                ],
            ];
        }

        if (! $ticket->isActive()) {
            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'revoked_ticket',
                    'message' => 'Revoked Ticket',
                    'detail' => 'Ticket is revoked and not eligible for admission.',
                ],
            ];
        }

        if ($ticket->registration->event_id !== $event->id) {
            $ticketEventTitle = $ticket->registration->event->title ?? 'Another Event';

            return [
                'valid' => false,
                'error' => [
                    'http_code' => 422,
                    'status' => 'wrong_event',
                    'message' => 'Wrong Event',
                    'detail' => "This ticket was issued for: {$ticketEventTitle}.",
                ],
            ];
        }

        return [
            'valid' => true,
            'event' => $event,
            'access_point' => $accessPoint,
            'ticket' => $ticket,
            'scanned_secret' => $scannedSecret,
        ];
    }
}
