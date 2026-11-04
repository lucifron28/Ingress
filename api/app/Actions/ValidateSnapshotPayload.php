<?php

namespace App\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ValidateSnapshotPayload
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['transfer' => 'Invalid snapshot transfer.']);
    }

    private function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || array_is_list($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value))) {
            $this->invalid();
        }

        return $value;
    }

    private function id(mixed $value): void
    {
        if (! is_int($value) || $value < 1) {
            $this->invalid();
        }
    }

    private function text(mixed $value, int $max = 255, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (! is_string($value) || (! $nullable && $value === '') || mb_strlen($value) > $max || str_contains($value, "\0")) {
            $this->invalid();
        }
    }

    private function date(mixed $value, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (! is_string($value) || ! ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value)) || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            $this->invalid();
        }
    }

    private function unique(array &$seen, mixed $value): void
    {
        if (isset($seen[$value])) {
            $this->invalid();
        }
        $seen[$value] = true;
    }

    private function identity(mixed $value, bool $role, array &$users): void
    {
        $keys = $role ? ['id', 'name', 'email', 'role'] : ['id', 'name', 'email', 'student_id'];
        $user = $this->object($value, $keys);
        $this->id($user['id']);
        $this->text($user['name']);
        $this->text($user['email']);
        if ($role && ! in_array($user['role'], ['admin', 'organizer', 'staff', 'student'], true)) {
            $this->invalid();
        }
        if (! $role) {
            $this->text($user['student_id'], 255, true);
        }
        $previous = $users[$user['id']] ?? null;
        if ($previous && ($previous['name'] !== $user['name'] || $previous['email'] !== $user['email'] || ($role && isset($previous['role']) && $previous['role'] !== $user['role']))) {
            $this->invalid();
        }
        $users[$user['id']] = array_merge($previous ?? [], $user);
    }

    public function execute(string $json): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        $payload = $this->object($payload, ['schema_version', 'snapshot_id', 'version', 'event', 'access_points', 'staff', 'registrations']);
        if (! in_array($payload['schema_version'], [1, 2, 3], true) || ! is_string($payload['snapshot_id']) || ! Str::isUuid($payload['snapshot_id'])) {
            $this->invalid();
        }
        $this->id($payload['version']);
        $eventKeys = ['id', 'title', 'slug', 'description', 'capacity', 'status', 'starts_at', 'ends_at',
            'registration_opens_at', 'registration_closes_at', 'registration_closed_at', 'venue', 'organizer'];
        if ($payload['schema_version'] === 3) {
            $eventKeys[] = 're_entry_allowed';
        }
        $event = $this->object($payload['event'], $eventKeys);
        $this->id($event['id']);
        $this->id($event['capacity']);
        $this->text($event['title']);
        $this->text($event['slug']);
        $this->text($event['description'], 65535, true);
        if ($event['status'] !== 'published') {
            $this->invalid();
        }
        if ($payload['schema_version'] === 3 && $event['re_entry_allowed'] !== true) {
            $this->invalid();
        }
        foreach (['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at'] as $key) {
            $this->date($event[$key]);
        }
        $this->date($event['registration_closed_at'], true);
        if ($event['starts_at'] >= $event['ends_at'] || $event['registration_opens_at'] >= $event['registration_closes_at']) {
            $this->invalid();
        }
        $venue = $this->object($event['venue'], ['id', 'name', 'code', 'location']);
        $this->id($venue['id']);
        $this->text($venue['name']);
        $this->text($venue['code']);
        $this->text($venue['location'], 65535, true);
        $users = [];
        $this->identity($event['organizer'], true, $users);
        foreach (['access_points', 'staff', 'registrations'] as $key) {
            if (! is_array($payload[$key]) || ! array_is_list($payload[$key])) {
                $this->invalid();
            }
        }
        $gateIds = $gateCodes = [];
        foreach ($payload['access_points'] as $gate) {
            $gate = $this->object($gate, ['id', 'name', 'code', 'is_active']);
            $this->id($gate['id']);
            $this->text($gate['name']);
            $this->text($gate['code']);
            if (! is_bool($gate['is_active'])) {
                $this->invalid();
            }
            $this->unique($gateIds, $gate['id']);
            $this->unique($gateCodes, $gate['code']);
        }
        $assignmentIds = $staffIds = [];
        foreach ($payload['staff'] as $assignment) {
            $assignment = $this->object($assignment, ['id', 'access_point_id', 'user']);
            $this->id($assignment['id']);
            if ($assignment['access_point_id'] !== null && (! is_int($assignment['access_point_id']) || ! isset($gateIds[$assignment['access_point_id']]))) {
                $this->invalid();
            }
            $this->identity($assignment['user'], true, $users);
            $this->unique($assignmentIds, $assignment['id']);
            $this->unique($staffIds, $assignment['user']['id']);
        }
        $registrationIds = $attendeeIds = $attendeeEmails = $ticketIds = $publicIds = $hashes = [];
        $confirmed = 0;
        foreach ($payload['registrations'] as $registration) {
            $registration = $this->object($registration, ['id', 'status', 'created_at', 'cancelled_at', 'attendee', 'ticket']);
            $this->id($registration['id']);
            $this->unique($registrationIds, $registration['id']);
            if (! in_array($registration['status'], ['confirmed', 'cancelled'], true)) {
                $this->invalid();
            }
            $this->date($registration['created_at']);
            $this->date($registration['cancelled_at'], true);
            $attendee = $registration['attendee'];
            if ($payload['schema_version'] >= 2) {
                $attendee = $this->object($attendee, ['id', 'name', 'email', 'student_id', 'kind']);
                $kind = $attendee['kind'];
                unset($attendee['kind']);
                if ($kind === 'student') {
                    $this->identity($attendee, false, $users);
                    $this->unique($attendeeIds, $attendee['id']);
                } elseif (in_array($kind, ['guest', 'alumni'], true)) {
                    if ($attendee['id'] !== null || $attendee['student_id'] !== null) {
                        $this->invalid();
                    }
                    $this->text($attendee['name']);
                    $this->text($attendee['email']);
                    if (! filter_var($attendee['email'], FILTER_VALIDATE_EMAIL) || mb_strtolower($attendee['email']) !== $attendee['email']) {
                        $this->invalid();
                    }
                } else {
                    $this->invalid();
                }
                $this->unique($attendeeEmails, mb_strtolower($attendee['email']));
            } else {
                $this->identity($attendee, false, $users);
                $this->unique($attendeeIds, $attendee['id']);
            }
            if ($registration['status'] === 'confirmed') {
                $confirmed++;
                if ($registration['ticket'] === null) {
                    $this->invalid();
                }
            }
            if ($registration['ticket'] === null) {
                continue;
            }
            $ticket = $this->object($registration['ticket'], ['id', 'public_id', 'qr_hash', 'status', 'credential_version', 'issued_at', 'revoked_at']);
            $this->id($ticket['id']);
            $this->id($ticket['credential_version']);
            if (! is_string($ticket['public_id']) || ! preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $ticket['public_id']) || ! is_string($ticket['qr_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/', $ticket['qr_hash']) || ! in_array($ticket['status'], ['active', 'revoked'], true)) {
                $this->invalid();
            }
            $this->date($ticket['issued_at']);
            $this->date($ticket['revoked_at'], true);
            $this->unique($ticketIds, $ticket['id']);
            $this->unique($publicIds, $ticket['public_id']);
            $this->unique($hashes, $ticket['qr_hash']);
        }
        if ($confirmed > $event['capacity']) {
            $this->invalid();
        }

        return $payload;
    }
}
