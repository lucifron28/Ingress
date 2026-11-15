<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class EventSchedule
{
    public const TIMEZONE = 'Asia/Manila';

    private const INPUT_FORMAT = '!Y-m-d\TH:i';

    private const DISPLAY_FORMAT = 'Y-m-d\TH:i';

    private const DATABASE_FORMAT = 'Y-m-d H:i:s';

    public static function local(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE);
    }

    public static function localInput(?DateTimeInterface $value): string
    {
        return $value === null ? '' : self::local($value)->format(self::DISPLAY_FORMAT);
    }

    /**
     * @param  array{registration_opens_at: string, registration_closes_at: string, starts_at: string, ends_at: string}  $values
     * @return array{registration_opens_at: string, registration_closes_at: string, starts_at: string, ends_at: string}
     */
    public static function utcDatabaseValues(array $values): array
    {
        $times = [];
        foreach (['registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at'] as $field) {
            $times[$field] = self::parseLocalInput($values[$field]);
        }

        $errors = [];
        if (! $times['registration_opens_at']->lt($times['registration_closes_at'])) {
            $errors['registration_closes_at'][] = 'Registration must close after it opens.';
        }

        if (! $times['registration_closes_at']->lt($times['starts_at'])) {
            $errors['registration_closes_at'][] = 'Registration must close before the event starts.';
        }

        if (! $times['starts_at']->lt($times['ends_at'])) {
            $errors['ends_at'][] = 'Event end time must be after its start time.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(
            fn (CarbonImmutable $time): string => $time->format(self::DATABASE_FORMAT),
            $times,
        );
    }

    private static function parseLocalInput(string $value): CarbonImmutable
    {
        $time = CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $value, self::TIMEZONE);

        if ($time === false || $time->format(self::DISPLAY_FORMAT) !== $value) {
            throw new \InvalidArgumentException('Event times must use the datetime-local format.');
        }

        return $time->utc();
    }
}
