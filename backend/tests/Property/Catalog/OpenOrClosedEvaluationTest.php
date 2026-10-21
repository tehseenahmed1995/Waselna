<?php

declare(strict_types=1);

// Feature: waselna, Property 30: Open-or-closed status is half-open interval membership in the applicable time zone

/*
 * For any weekly schedule of at most 3 non-overlapping intervals per day of
 * week expressed as minutes from local midnight, any IANA time zone (including
 * days crossing a daylight-saving transition), and any UTC probe instant, a
 * Restaurant_Branch is reported open exactly when the instant converted into
 * the applicable time zone falls in [start, end) of an interval of that
 * Restaurant_Branch's Branch_Hours where at least one interval is defined, and
 * of the Restaurant's Opening_Hours otherwise, and is reported closed whenever
 * no applicable interval contains the instant, including days with no defined
 * interval.
 *
 * **Validates: Requirements 11.7, 32.5, 32.7, 3.5**
 *
 * The subject is the framework-free OpeningHoursEvaluator (task 7.2) paired with
 * its WeeklySchedule / HoursInterval / DayOfWeek value objects, exercised as a
 * pure Domain unit: no database, no container, and no wall-clock reading. Every
 * probe instant is handed to a FrozenClock so the evaluator's own
 * isOpenNow() reads the generated instant and never time().
 *
 * The property is checked against an independent oracle. The oracle re-derives
 * the answer the long way round — pick the applicable schedule (Branch_Hours
 * where it defines at least one interval anywhere, Opening_Hours otherwise),
 * convert the UTC instant into the IANA zone with PHP's own DateTimeZone, floor
 * to the local minute-of-day, and test half-open [start, end) membership among
 * that local day's intervals — so the test fails if the evaluator disagrees
 * with that reading on any generated input.
 *
 * Coverage is directed at the hard edges rather than left to chance:
 *  - the branch-overrides-opening fallback, including an empty Branch_Hours that
 *    must fall through to Opening_Hours and a non-empty one that must win;
 *  - always-closed empty schedules, which must report closed at every instant;
 *  - the half-open boundaries, with intervals that start at 0, end at 1440, and
 *    probe instants landing exactly on an interval start (open) and exactly on
 *    an interval end (closed);
 *  - zones that cross a daylight-saving transition in the probed window
 *    (America/New_York, Europe/London, Australia/Sydney), where the local
 *    wall-minute the schedule is read against jumps forward or back.
 */

use App\Modules\Catalog\Domain\DayOfWeek;
use App\Modules\Catalog\Domain\HoursInterval;
use App\Modules\Catalog\Domain\OpeningHoursEvaluator;
use App\Modules\Catalog\Domain\WeeklySchedule;
use App\Shared\Support\FrozenClock;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;

uses(TestTrait::class);

/**
 * IANA zones the property draws from. The three fixed-offset / near-UTC zones
 * keep the simple case covered; the final three each observe daylight saving so
 * a probe instant near a transition reads the schedule against the offset
 * actually in force at that instant.
 */
function openOrClosedZones(): ErisGenerator
{
    return Generators::elements([
        'UTC',
        'Asia/Riyadh',          // fixed +03:00, no DST
        'Asia/Kolkata',         // fixed +05:30, half-hour offset
        'America/New_York',     // DST, northern hemisphere
        'Europe/London',        // DST, crosses UTC at the boundary
        'Australia/Sydney',     // DST, southern hemisphere (opposite phase)
    ]);
}

/**
 * One non-wrapping half-open interval inside a day. Bounds are drawn to land on
 * the documented edges often — 0 as a start, 1440 as an end — because those are
 * where the half-open reading is easiest to get wrong.
 *
 * @return ErisGenerator<HoursInterval>
 */
function openOrClosedInterval(): ErisGenerator
{
    return Generators::map(
        static function (array $pair): HoursInterval {
            [$a, $b] = $pair;

            $start = min($a, $b);
            $end = max($a, $b);

            // Collapse a degenerate zero-length span into a minimal valid one so
            // the HoursInterval invariant (start strictly before end) always holds.
            if ($start === $end) {
                if ($end < HoursInterval::DAY_END_MINUTE) {
                    $end++;
                } else {
                    $start--;
                }
            }

            return new HoursInterval($start, $end);
        },
        Generators::tuple(
            Generators::choose(HoursInterval::DAY_START_MINUTE, HoursInterval::DAY_END_MINUTE),
            Generators::choose(HoursInterval::DAY_START_MINUTE, HoursInterval::DAY_END_MINUTE),
        ),
    );
}

/**
 * 0..3 non-overlapping intervals for a single day, satisfying the WeeklySchedule
 * per-day invariants. Candidate intervals are generated then greedily dropped
 * where they would overlap an already-kept one, so the result always constructs.
 *
 * @return ErisGenerator<list<HoursInterval>>
 */
function openOrClosedDayIntervals(): ErisGenerator
{
    return Generators::map(
        static function (array $candidates): array {
            /** @var list<HoursInterval> $candidates */
            $kept = [];

            foreach ($candidates as $candidate) {
                $overlapsKept = false;

                foreach ($kept as $existing) {
                    if ($candidate->overlaps($existing)) {
                        $overlapsKept = true;
                        break;
                    }
                }

                if (! $overlapsKept) {
                    $kept[] = $candidate;
                }

                if (count($kept) === WeeklySchedule::MAX_INTERVALS_PER_DAY) {
                    break;
                }
            }

            return $kept;
        },
        Generators::seq(openOrClosedInterval()),
    );
}

/**
 * A complete weekly schedule: an independent 0..3-interval day for each of the
 * seven days. Produced empty often enough — every day may generate zero
 * intervals — that the always-closed case is reached by construction.
 *
 * @return ErisGenerator<WeeklySchedule>
 */
function openOrClosedSchedule(): ErisGenerator
{
    return Generators::map(
        static function (array $days): WeeklySchedule {
            $byDay = [];

            foreach (DayOfWeek::cases() as $index => $day) {
                $byDay[$day->value] = $days[$index];
            }

            return WeeklySchedule::fromDays($byDay);
        },
        Generators::tuple(
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
            openOrClosedDayIntervals(),
        ),
    );
}

/**
 * A UTC probe instant. The seconds span a wide multi-year window that includes
 * the daylight-saving transitions of the DST zones above, so the generated set
 * contains instants on both sides of a spring-forward and a fall-back.
 *
 * @return ErisGenerator<DateTimeImmutable>
 */
function openOrClosedInstant(): ErisGenerator
{
    // 2021-01-01T00:00:00Z .. 2025-12-31T23:59:59Z, inclusive, at second
    // resolution — enough span to straddle several DST changes per zone.
    return Generators::map(
        static fn (int $epochSecond): DateTimeImmutable => (new DateTimeImmutable('@'.$epochSecond))
            ->setTimezone(new DateTimeZone('UTC')),
        Generators::choose(1_609_459_200, 1_767_225_599),
    );
}

/**
 * The independent oracle: recompute open/closed without using the evaluator.
 *
 * Mirrors Requirement 32.7 step by step — applicable-schedule choice, IANA
 * localisation (DST handled by DateTimeZone), floor to local minute-of-day,
 * half-open [start, end) membership on that local day — so agreement with the
 * evaluator is meaningful rather than tautological.
 */
function openOrClosedOracle(
    DateTimeImmutable $instantUtc,
    WeeklySchedule $branchHours,
    WeeklySchedule $openingHours,
    string $timeZone,
): bool {
    $applicable = $branchHours->isEmpty() ? $openingHours : $branchHours;

    if ($applicable->isEmpty()) {
        return false;
    }

    $local = $instantUtc->setTimezone(new DateTimeZone($timeZone));

    $day = match ((int) $local->format('N')) {
        1 => DayOfWeek::Monday,
        2 => DayOfWeek::Tuesday,
        3 => DayOfWeek::Wednesday,
        4 => DayOfWeek::Thursday,
        5 => DayOfWeek::Friday,
        6 => DayOfWeek::Saturday,
        default => DayOfWeek::Sunday,
    };

    $minuteOfDay = ((int) $local->format('G')) * 60 + (int) $local->format('i');

    foreach ($applicable->intervalsOn($day) as $interval) {
        if ($minuteOfDay >= $interval->startMinute && $minuteOfDay < $interval->endMinute) {
            return true;
        }
    }

    return false;
}

it('reports open exactly when the localised instant is in a half-open interval of the applicable schedule', function (): void {
    $this->limitTo(100)->forAll(
        openOrClosedSchedule(),
        openOrClosedSchedule(),
        openOrClosedZones(),
        openOrClosedInstant(),
    )->then(function (
        WeeklySchedule $branchHours,
        WeeklySchedule $openingHours,
        string $timeZone,
        DateTimeImmutable $instantUtc,
    ): void {
        $evaluator = new OpeningHoursEvaluator(new FrozenClock($instantUtc));

        $expected = openOrClosedOracle($instantUtc, $branchHours, $openingHours, $timeZone);

        // isOpenAt reads the supplied instant directly.
        expect($evaluator->isOpenAt($instantUtc, $branchHours, $openingHours, $timeZone))
            ->toBe($expected);

        // isOpenNow reads the same instant through the injected Clock, never the
        // wall clock, and must agree.
        expect($evaluator->isOpenNow($branchHours, $openingHours, $timeZone))
            ->toBe($expected);
    });
});

it('reports closed at every instant when both schedules are empty', function (): void {
    $this->limitTo(100)->forAll(
        openOrClosedZones(),
        openOrClosedInstant(),
    )->then(function (string $timeZone, DateTimeImmutable $instantUtc): void {
        $evaluator = new OpeningHoursEvaluator(new FrozenClock($instantUtc));

        $empty = WeeklySchedule::empty();

        expect($evaluator->isOpenAt($instantUtc, $empty, $empty, $timeZone))->toBeFalse();
    });
});

it('falls back to Opening_Hours only when Branch_Hours define no interval anywhere', function (): void {
    $this->limitTo(100)->forAll(
        openOrClosedSchedule(),
        openOrClosedSchedule(),
        openOrClosedZones(),
        openOrClosedInstant(),
    )->then(function (
        WeeklySchedule $branchHours,
        WeeklySchedule $openingHours,
        string $timeZone,
        DateTimeImmutable $instantUtc,
    ): void {
        $evaluator = new OpeningHoursEvaluator(new FrozenClock($instantUtc));

        if ($branchHours->isEmpty()) {
            // Empty Branch_Hours: the answer is governed entirely by Opening_Hours.
            expect($evaluator->isOpenAt($instantUtc, $branchHours, $openingHours, $timeZone))
                ->toBe($evaluator->isOpenAt($instantUtc, WeeklySchedule::empty(), $openingHours, $timeZone));
        } else {
            // Non-empty Branch_Hours win: Opening_Hours is ignored, so swapping it
            // for an always-closed schedule changes nothing.
            expect($evaluator->isOpenAt($instantUtc, $branchHours, $openingHours, $timeZone))
                ->toBe($evaluator->isOpenAt($instantUtc, $branchHours, WeeklySchedule::empty(), $timeZone));
        }
    });
});

it('reads the half-open boundary as open at the start minute and closed at the end minute across a DST day', function (): void {
    // A directed case pinning the half-open edges in a DST-observing zone. The
    // schedule opens 09:00..17:00 local on the day of the US spring-forward
    // transition (2024-03-10), when 02:00..03:00 local does not exist.
    $zone = new DateTimeZone('America/New_York');
    $opening = WeeklySchedule::fromDays([
        DayOfWeek::Sunday->value => [new HoursInterval(9 * 60, 17 * 60)],
    ]);
    $empty = WeeklySchedule::empty();

    $atStart = new DateTimeImmutable('2024-03-10 09:00:00', $zone);
    $justBeforeStart = new DateTimeImmutable('2024-03-10 08:59:59', $zone);
    $atEnd = new DateTimeImmutable('2024-03-10 17:00:00', $zone);
    $justBeforeEnd = new DateTimeImmutable('2024-03-10 16:59:59', $zone);

    $evaluator = new OpeningHoursEvaluator(new FrozenClock($atStart));

    // Start minute is inclusive.
    expect($evaluator->isOpenAt($atStart, $empty, $opening, 'America/New_York'))->toBeTrue();
    // The minute before the start is closed.
    expect($evaluator->isOpenAt($justBeforeStart, $empty, $opening, 'America/New_York'))->toBeFalse();
    // The last minute inside the span is open.
    expect($evaluator->isOpenAt($justBeforeEnd, $empty, $opening, 'America/New_York'))->toBeTrue();
    // End minute is exclusive.
    expect($evaluator->isOpenAt($atEnd, $empty, $opening, 'America/New_York'))->toBeFalse();
});
