<?php

namespace App\Actions;

use App\Models\Registration;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueGuestTicket
{
    public function __construct(private RegisterAttendeeForEvent $registration) {}

    /**
     * @return array{registration: Registration, redemption_code: string}
     */
    public function execute(int $eventId, string $name, string $email, string $type): array
    {
        $name = trim($name);
        $email = Str::lower(trim($email));
        if (config('ingress.mode') !== 'cloud' || ! in_array($type, ['guest', 'alumni'], true)
            || $name === '' || mb_strlen($name) > 255 || str_contains($name, "\0")
            || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['guest' => 'Provide a valid guest name, email, and attendee type.']);
        }

        try {
            $result = $this->registration->execute(
                eventId: $eventId,
                categoryId: null,
                guestName: $name,
                guestEmail: $email,
                guestType: $type,
            );

            return [
                'registration' => $result['registration'],
                'redemption_code' => (string) $result['redemption_code'],
            ];
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'guest' => collect($exception->errors())->flatten()->all(),
            ]);
        }
    }
}
