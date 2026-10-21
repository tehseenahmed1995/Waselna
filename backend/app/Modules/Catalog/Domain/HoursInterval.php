<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

/**
 * A single opening interval of one local day, expressed as minutes from local
 * midnight and read as the half-open interval `[start, end)` (Requirements
 * 11.7, 32.5, 32.7).
 *
 * The invariants mirror the storage checks of the `opening_hours` /
 * `branch_hours` tables (design data model, Requirements 11.7, 32.5): both
 * bounds lie in the closed range 0 to 1,440, and the start is strictly less
 * than the end. 1,440 is local midnight at the end of the day, which an
 * interval may reach but, being the excluded upper bound, never includes — the
 * following day's first interval owns that instant.
 *
 * Intervals never wrap past local midnight. A trading period that crosses
 * midnight is stored as two intervals on the two local days it touches (for
 * example `[1320, 1440)` on one day and `[0, 120)` on the next), which keeps
 * every interval a plain same-day half-open range and keeps the per-day overlap
 * rule decidable.
 *
 * The value object carries no day; a {@see WeeklySchedule} binds intervals to
 * their {@see DayOfWeek}.
 */
final readonly class HoursInterval
{
    public const DAY_START_MINUTE = 0;

    public const DAY_END_MINUTE = 1440;

    public function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {
        if ($startMinute < self::DAY_START_MINUTE || $startMinute > self::DAY_END_MINUTE) {
            throw new InvalidArgumentException(sprintf(
                'An interval start lies in the closed range %d to %d; received %d.',
                self::DAY_START_MINUTE,
                self::DAY_END_MINUTE,
                $startMinute,
            ));
        }

        if ($endMinute < self::DAY_START_MINUTE || $endMinute > self::DAY_END_MINUTE) {
            throw new InvalidArgumentException(sprintf(
                'An interval end lies in the closed range %d to %d; received %d.',
                self::DAY_START_MINUTE,
                self::DAY_END_MINUTE,
                $endMinute,
            ));
        }

        if ($startMinute >= $endMinute) {
            throw new InvalidArgumentException(sprintf(
                'An interval start is strictly before its end; received start %d and end %d.',
                $startMinute,
                $endMinute,
            ));
        }
    }

    public static function of(int $startMinute, int $endMinute): self
    {
        return new self($startMinute, $endMinute);
    }

    /**
     * True while the given minute-of-day falls at or after the start and
     * strictly before the end — the half-open reading of Requirement 32.7.
     */
    public function contains(int $minuteOfDay): bool
    {
        return $minuteOfDay >= $this->startMinute && $minuteOfDay < $this->endMinute;
    }

    /**
     * True when the two intervals share at least one minute. Intervals that
     * merely touch — one ending exactly where the other starts — do not
     * overlap, the same half-open convention {@see self::contains()} uses. This
     * is the per-day overlap rule the schedule enforces (Requirement 32.5).
     */
    public function overlaps(self $other): bool
    {
        return $this->startMinute < $other->endMinute && $other->startMinute < $this->endMinute;
    }
}
