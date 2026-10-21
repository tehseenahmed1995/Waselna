<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

/**
 * A complete weekly opening schedule: for each {@see DayOfWeek}, the ordered
 * list of that day's {@see HoursInterval}s (Requirements 11.7, 32.5).
 *
 * This is the framework-free, in-memory shape of an Opening_Hours or a
 * Branch_Hours record. The management endpoints map stored rows into it and the
 * {@see OpeningHoursEvaluator} reads it; neither the schedule nor the evaluator
 * ever touches a database or a framework type.
 *
 * Construction enforces the storage invariants of the design data model
 * (Requirements 11.7, 32.5):
 *
 *  - at most 3 intervals per day of week;
 *  - no two intervals of the same day overlap (half-open reading, so touching
 *    intervals are allowed).
 *
 * A day that carries no interval is closed for the whole of that local day
 * (Requirement 32.5). An empty schedule — no interval on any day — is a valid,
 * always-closed schedule; the evaluator uses {@see self::isEmpty()} to decide
 * whether a Restaurant_Branch's Branch_Hours override the Restaurant's
 * Opening_Hours (Requirement 32.7).
 */
final readonly class WeeklySchedule
{
    public const MAX_INTERVALS_PER_DAY = 3;

    /**
     * @var array<string, list<HoursInterval>> keyed by DayOfWeek value
     */
    private array $intervalsByDay;

    /**
     * @param  array<string, array<int, HoursInterval>>  $intervalsByDay  keyed by DayOfWeek value
     */
    public function __construct(array $intervalsByDay)
    {
        $normalised = [];

        foreach (DayOfWeek::cases() as $day) {
            $dayIntervals = array_values($intervalsByDay[$day->value] ?? []);

            self::assertDay($day, $dayIntervals);

            $normalised[$day->value] = $dayIntervals;
        }

        $this->intervalsByDay = $normalised;
    }

    /**
     * An always-closed schedule with no interval on any day.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, array<int, HoursInterval>>  $intervalsByDay  keyed by DayOfWeek value
     */
    public static function fromDays(array $intervalsByDay): self
    {
        return new self($intervalsByDay);
    }

    /**
     * The intervals defined for one day, in input order.
     *
     * @return list<HoursInterval>
     */
    public function intervalsOn(DayOfWeek $day): array
    {
        return $this->intervalsByDay[$day->value];
    }

    /**
     * True when no interval is defined on any day, which makes the schedule
     * always closed and, for a Restaurant_Branch, means its Branch_Hours do not
     * override the Restaurant's Opening_Hours (Requirement 32.7).
     */
    public function isEmpty(): bool
    {
        foreach ($this->intervalsByDay as $dayIntervals) {
            if ($dayIntervals !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<HoursInterval>  $dayIntervals
     */
    private static function assertDay(DayOfWeek $day, array $dayIntervals): void
    {
        $count = count($dayIntervals);

        if ($count > self::MAX_INTERVALS_PER_DAY) {
            throw new InvalidArgumentException(sprintf(
                '%s carries at most %d intervals; received %d.',
                $day->value,
                self::MAX_INTERVALS_PER_DAY,
                $count,
            ));
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($dayIntervals[$i]->overlaps($dayIntervals[$j])) {
                    throw new InvalidArgumentException(sprintf(
                        'Two intervals of %s overlap: [%d, %d) and [%d, %d).',
                        $day->value,
                        $dayIntervals[$i]->startMinute,
                        $dayIntervals[$i]->endMinute,
                        $dayIntervals[$j]->startMinute,
                        $dayIntervals[$j]->endMinute,
                    ));
                }
            }
        }
    }
}
