<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exceptions\ValidationException;

/**
 * Validates a submitted weekly schedule and turns it into a {@see WeeklySchedule},
 * reporting every interval rule violation at once (Requirements 11.8, 32.6).
 *
 * The hours management endpoints accept a weekly schedule as a map of day name
 * to a list of `{start_minute, end_minute}` intervals. This parser is the single
 * place that holds the interval rules of Requirement 32 criterion 5 and the
 * rejection shape of criterion 6:
 *
 *  - a day of week outside the seven permitted values;
 *  - a start or end minute outside the closed range 0 to 1,440;
 *  - a start minute greater than or equal to its end minute;
 *  - more than three intervals for one day of week;
 *  - two intervals of the same day of week that overlap (half-open, so touching
 *    intervals do not).
 *
 * Unlike {@see WeeklySchedule}'s own constructor — which raises a generic
 * {@see \InvalidArgumentException} on the first invariant breach and is meant for
 * already-valid in-memory construction — this parser inspects every submitted
 * interval and collects one per-field detail for each violating one, then raises
 * a single {@see ValidationException} carrying the `invalid_hours` code and all
 * those details (Requirement 32.6: "one per-field validation detail for each
 * violating interval"). A conforming submission produces a {@see WeeklySchedule}
 * and no detail for any conforming interval.
 *
 * The parser is pure: it reads the submitted structure, touches no database, and
 * either returns a value object or throws. The service calls it before opening
 * the replacement transaction, so a rejected submission changes nothing
 * (Requirement 32.6, 11.8).
 */
final class SubmittedSchedule
{
    public const ERROR_CODE = 'invalid_hours';

    /**
     * The seven permitted day-of-week names, in the canonical week order the
     * response details are grouped by.
     *
     * @var list<string>
     */
    private const DAY_NAMES = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * Validates the submitted schedule and returns the {@see WeeklySchedule} it
     * denotes.
     *
     * @param  array<string, list<array{start_minute: int, end_minute: int}>>  $submitted
     *                                                                                     the submitted schedule, already shape-validated by the Form_Request:
     *                                                                                     keys are strings and each interval is a pair of integers. The interval
     *                                                                                     *rules* (bounds, order, count, overlap) and the day-name set are checked
     *                                                                                     here, not by the Form_Request, so their violations carry `invalid_hours`
     *                                                                                     rather than `validation_failed` (Requirement 32.6).
     *
     * @throws ValidationException with `invalid_hours` and one per-field detail
     *                             for each violating interval when any rule is broken.
     */
    public static function validate(array $submitted): WeeklySchedule
    {
        /** @var array<string, list<string>> $fieldErrors */
        $fieldErrors = [];

        /** @var array<string, list<HoursInterval>> $byDay */
        $byDay = [];

        foreach ($submitted as $rawDay => $intervals) {
            // Array keys that look like integers arrive as ints, so normalise to
            // a string before the day-name comparison.
            $dayName = mb_strtolower(trim((string) $rawDay));

            if (! in_array($dayName, self::DAY_NAMES, true)) {
                $fieldErrors[$dayName === '' ? 'day_of_week' : $dayName][] = 'unknown_day_of_week';

                continue;
            }

            if (count($intervals) > WeeklySchedule::MAX_INTERVALS_PER_DAY) {
                $fieldErrors[$dayName][] = 'too_many_intervals';
            }

            $byDay[$dayName] = self::validateDay($dayName, $intervals, $fieldErrors);
        }

        if ($fieldErrors !== []) {
            throw new ValidationException(
                self::ERROR_CODE,
                'The submitted hours break an interval rule.',
                self::orderDetails($fieldErrors),
            );
        }

        return WeeklySchedule::fromDays($byDay);
    }

    /**
     * Validates one day's intervals, appending a per-interval detail for each
     * bad bound, bad order, or overlap, and returns the intervals that are
     * individually well-formed (bounds and order), so the schedule can still be
     * constructed once the whole submission is known valid.
     *
     * @param  list<array{start_minute: int, end_minute: int}>  $intervals
     * @param  array<string, list<string>>  $fieldErrors
     * @return list<HoursInterval>
     */
    private static function validateDay(string $dayName, array $intervals, array &$fieldErrors): array
    {
        /** @var list<HoursInterval> $wellFormed */
        $wellFormed = [];

        foreach ($intervals as $index => $interval) {
            $start = $interval['start_minute'];
            $end = $interval['end_minute'];

            $field = sprintf('%s.%d', $dayName, $index);

            $outOfRange = $start < HoursInterval::DAY_START_MINUTE
                || $start > HoursInterval::DAY_END_MINUTE
                || $end < HoursInterval::DAY_START_MINUTE
                || $end > HoursInterval::DAY_END_MINUTE;

            if ($outOfRange) {
                $fieldErrors[$field][] = 'minute_out_of_range';

                continue;
            }

            if ($start >= $end) {
                $fieldErrors[$field][] = 'start_not_before_end';

                continue;
            }

            $wellFormed[] = new HoursInterval($start, $end);
        }

        self::flagOverlaps($dayName, $wellFormed, $fieldErrors);

        return $wellFormed;
    }

    /**
     * Flags every well-formed interval of a day that overlaps an earlier one,
     * so each member of an overlapping pair receives its own per-field detail
     * (Requirement 32.6: a detail "for each violating interval").
     *
     * @param  list<HoursInterval>  $intervals
     * @param  array<string, list<string>>  $fieldErrors
     */
    private static function flagOverlaps(string $dayName, array $intervals, array &$fieldErrors): void
    {
        $count = count($intervals);

        /** @var array<int, true> $flagged */
        $flagged = [];

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (! $intervals[$i]->overlaps($intervals[$j])) {
                    continue;
                }

                foreach ([$i, $j] as $overlapping) {
                    if (isset($flagged[$overlapping])) {
                        continue;
                    }

                    $flagged[$overlapping] = true;
                    $fieldErrors[sprintf('%s.%d', $dayName, $overlapping)][] = 'overlapping_interval';
                }
            }
        }
    }

    /**
     * Orders the collected details by day of week and then by the numeric
     * interval index, so the response lists violations in a stable, readable
     * order rather than submission order.
     *
     * @param  array<string, list<string>>  $fieldErrors
     * @return array<string, list<string>>
     */
    private static function orderDetails(array $fieldErrors): array
    {
        uksort($fieldErrors, static function (string $a, string $b): int {
            [$dayA, $indexA] = self::splitField($a);
            [$dayB, $indexB] = self::splitField($b);

            $rankA = array_search($dayA, self::DAY_NAMES, true);
            $rankB = array_search($dayB, self::DAY_NAMES, true);

            $rankA = $rankA === false ? count(self::DAY_NAMES) : $rankA;
            $rankB = $rankB === false ? count(self::DAY_NAMES) : $rankB;

            return [$rankA, $indexA, $a] <=> [$rankB, $indexB, $b];
        });

        return $fieldErrors;
    }

    /**
     * Splits a `day.index` field key into its day name and numeric index; a key
     * carrying no index (an unknown-day key) sorts before any interval of its
     * day.
     *
     * @return array{0: string, 1: int}
     */
    private static function splitField(string $field): array
    {
        if (! str_contains($field, '.')) {
            return [$field, -1];
        }

        [$day, $index] = explode('.', $field, 2);

        return [$day, is_numeric($index) ? (int) $index : -1];
    }
}
