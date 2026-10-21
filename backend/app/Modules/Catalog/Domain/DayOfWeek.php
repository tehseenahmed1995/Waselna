<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use DateTimeInterface;

/**
 * The seven days of the week an Opening_Hours or Branch_Hours interval may name
 * (Requirements 32.5, 11.7).
 *
 * The backing values are the lowercase day names the API accepts and stores, so
 * a stored `day_of_week` column maps straight onto a case through
 * {@see self::fromName()}. {@see self::fromLocalDate()} maps a local calendar
 * instant onto its day, which is how the OpeningHoursEvaluator turns a wall
 * instant in the applicable time zone into the day whose intervals apply.
 */
enum DayOfWeek: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    /**
     * The day a local calendar instant falls on.
     *
     * The instant is read in whatever time zone it already carries; the caller
     * (the OpeningHoursEvaluator) is responsible for converting a UTC instant
     * into the applicable IANA time zone first, so the day read here is the
     * local day and never the UTC day.
     */
    public static function fromLocalDate(DateTimeInterface $localInstant): self
    {
        // PHP's `N` format is ISO-8601: 1 (Monday) through 7 (Sunday).
        return match ((int) $localInstant->format('N')) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            default => self::Sunday,
        };
    }
}
