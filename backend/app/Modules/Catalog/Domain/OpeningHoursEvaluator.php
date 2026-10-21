<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Support\Clock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Decides whether a Restaurant_Branch is open at a given instant (Requirements
 * 3.5, 11.7, 32.5, 32.7).
 *
 * The component is framework-free: it depends only on PHP's date types, the
 * injected {@see Clock}, and the Catalog Domain value objects
 * ({@see WeeklySchedule}, {@see HoursInterval}, {@see DayOfWeek}). It performs no
 * database access and never calls `now()`, `time()`, or `Carbon::now()` — every
 * time-dependent answer flows from the instant the caller supplies, which the
 * {@see Clock} provides in {@see self::isOpenNow()}.
 *
 * The decision, exactly as Requirement 32.7 states it:
 *
 *  1. Choose the applicable schedule. Where the Restaurant_Branch defines at
 *     least one Branch_Hours interval, its Branch_Hours apply; otherwise the
 *     Restaurant's Opening_Hours apply. {@see WeeklySchedule::isEmpty()} draws
 *     that line, so a Restaurant_Branch with no interval on any day falls back
 *     to Opening_Hours rather than being treated as always closed.
 *  2. Localise the instant. Convert the supplied UTC instant into the
 *     applicable IANA time zone. The time zone's own rules — including
 *     daylight-saving transitions — are applied by PHP's {@see DateTimeZone}
 *     during the conversion, so a wall instant is read against the offset that
 *     was actually in force at that instant.
 *  3. Test membership. Compute the local day of week and the minutes elapsed
 *     since local midnight, and report open where some interval of that local
 *     day contains the minute under the half-open `[start, end)` reading. A day
 *     with no interval — and the whole of an always-closed schedule — reports
 *     closed.
 *
 * Minute-of-day is floored (seconds and sub-seconds are dropped) so that the
 * local minute is compared against the integer minute bounds the schedule
 * stores. The start minute is therefore inclusive to the second and the end
 * minute exclusive from its first second, which is the half-open contract.
 */
final readonly class OpeningHoursEvaluator
{
    public function __construct(private Clock $clock) {}

    /**
     * Whether the Restaurant_Branch is open at the current instant, read from
     * the injected {@see Clock} (never the wall clock directly).
     *
     * @param  WeeklySchedule  $branchHours  the Restaurant_Branch's Branch_Hours; may be empty
     * @param  WeeklySchedule  $openingHours  the Restaurant's Opening_Hours, applied when Branch_Hours are empty
     * @param  string  $timeZone  the applicable IANA time zone identifier
     */
    public function isOpenNow(
        WeeklySchedule $branchHours,
        WeeklySchedule $openingHours,
        string $timeZone,
    ): bool {
        return $this->isOpenAt($this->clock->nowUtc(), $branchHours, $openingHours, $timeZone);
    }

    /**
     * Whether the Restaurant_Branch is open at the supplied instant.
     *
     * The instant is read in whatever time zone it carries and converted into
     * the applicable IANA time zone before evaluation, so a caller may pass a
     * UTC instant or any other zoned instant and get the same answer.
     *
     * @param  WeeklySchedule  $branchHours  the Restaurant_Branch's Branch_Hours; may be empty
     * @param  WeeklySchedule  $openingHours  the Restaurant's Opening_Hours, applied when Branch_Hours are empty
     * @param  string  $timeZone  the applicable IANA time zone identifier
     */
    public function isOpenAt(
        DateTimeInterface $instant,
        WeeklySchedule $branchHours,
        WeeklySchedule $openingHours,
        string $timeZone,
    ): bool {
        $applicable = $branchHours->isEmpty() ? $openingHours : $branchHours;

        if ($applicable->isEmpty()) {
            return false;
        }

        $local = $this->localise($instant, $timeZone);

        $day = DayOfWeek::fromLocalDate($local);
        $minuteOfDay = $this->minutesSinceLocalMidnight($local);

        foreach ($applicable->intervalsOn($day) as $interval) {
            if ($interval->contains($minuteOfDay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert the instant into the applicable IANA time zone, leaving the
     * absolute instant unchanged and only re-expressing it against that zone's
     * offset in force at that instant.
     */
    private function localise(DateTimeInterface $instant, string $timeZone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone($timeZone));
    }

    /**
     * Whole minutes elapsed since local midnight, in the closed range 0 to
     * 1,439. Seconds and finer are floored away so the comparison is against
     * the integer minute bounds a schedule stores.
     */
    private function minutesSinceLocalMidnight(DateTimeImmutable $local): int
    {
        return ((int) $local->format('G')) * 60 + (int) $local->format('i');
    }
}
