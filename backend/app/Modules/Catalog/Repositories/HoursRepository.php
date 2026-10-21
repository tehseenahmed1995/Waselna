<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Domain\DayOfWeek;
use App\Modules\Catalog\Domain\HoursInterval;
use App\Modules\Catalog\Domain\WeeklySchedule;
use App\Modules\Catalog\Models\BranchHour;
use App\Modules\Catalog\Models\OpeningHour;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Shared\Support\IdGenerator;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that queries the `opening_hours` and `branch_hours` tables
 * (Requirements 11.7, 11.8, 32.5, 32.6).
 *
 * Concrete and Eloquent-backed, following the repository deviation the design
 * records: the HoursManagementServiceImplementation
 * holds the rules, this holds the queries. The two tables are structurally
 * identical and differ only in their owner column, so one repository serves both
 * through a shared private helper keyed on model class and owner column.
 *
 * The replacement writes — {@see self::replaceOpeningHours()} and
 * {@see self::replaceBranchHours()} — are a delete-all-then-insert-all over one
 * owner. They are expected to run inside the service's transaction, so the
 * delete and the inserts commit together or not at all (Requirement 40.8); the
 * repository itself opens no transaction.
 */
final class HoursRepository
{
    public function __construct(private readonly IdGenerator $ids) {}

    /**
     * Locks the Restaurant row for the duration of the surrounding transaction
     * and returns it, or null where no such Restaurant exists. The lock
     * serialises a concurrent schedule replacement against the same Restaurant,
     * so the delete-all-then-insert-all cannot interleave with another.
     */
    public function lockRestaurant(string $restaurantId): ?Restaurant
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('id', $restaurantId)
            ->lockForUpdate()
            ->first();

        return $restaurant;
    }

    /**
     * Locks the Restaurant_Branch row for the duration of the surrounding
     * transaction and returns it, or null where no such Restaurant_Branch
     * exists. The locked row carries `restaurant_id`, which the service uses to
     * decide whether a Restaurant_Operator owns the Restaurant_Branch.
     */
    public function lockBranch(string $branchId): ?RestaurantBranch
    {
        /** @var RestaurantBranch|null $branch */
        $branch = RestaurantBranch::query()
            ->where('id', $branchId)
            ->lockForUpdate()
            ->first();

        return $branch;
    }

    /**
     * The Restaurant's complete Opening_Hours as a {@see WeeklySchedule}; an
     * empty schedule where it has no interval (Requirement 32.5).
     */
    public function openingHoursFor(string $restaurantId): WeeklySchedule
    {
        return $this->scheduleFor(OpeningHour::class, 'restaurant_id', $restaurantId);
    }

    /**
     * The Restaurant_Branch's complete Branch_Hours as a {@see WeeklySchedule};
     * an empty schedule where it has no interval, which the evaluator reads as
     * "fall back to Opening_Hours" (Requirement 32.7).
     */
    public function branchHoursFor(string $branchId): WeeklySchedule
    {
        return $this->scheduleFor(BranchHour::class, 'restaurant_branch_id', $branchId);
    }

    /**
     * Replaces the Restaurant's complete Opening_Hours with the submitted
     * schedule: every existing interval of the Restaurant is removed and the
     * submitted intervals inserted (Requirement 32.6, 11.7). Run inside the
     * service transaction.
     */
    public function replaceOpeningHours(string $restaurantId, WeeklySchedule $schedule): void
    {
        $this->replace(OpeningHour::class, 'restaurant_id', $restaurantId, $schedule);
    }

    /**
     * Replaces the Restaurant_Branch's complete Branch_Hours with the submitted
     * schedule (Requirement 32.6, 32.5). Run inside the service transaction.
     */
    public function replaceBranchHours(string $branchId, WeeklySchedule $schedule): void
    {
        $this->replace(BranchHour::class, 'restaurant_branch_id', $branchId, $schedule);
    }

    /**
     * Reads a schedule from one owner's rows, grouping intervals by their day.
     *
     * @param  class-string<OpeningHour|BranchHour>  $modelClass
     */
    private function scheduleFor(string $modelClass, string $ownerColumn, string $ownerId): WeeklySchedule
    {
        /** @var array<string, list<HoursInterval>> $byDay */
        $byDay = [];

        $rows = $modelClass::query()
            ->where($ownerColumn, $ownerId)
            ->orderBy('day_of_week')
            ->orderBy('start_minute')
            ->get();

        foreach ($rows as $row) {
            $byDay[$row->day_of_week][] = new HoursInterval($row->start_minute, $row->end_minute);
        }

        return WeeklySchedule::fromDays($byDay);
    }

    /**
     * The delete-all-then-insert-all a replacement performs for one owner. Every
     * interval of the schedule is expanded into one row per day; a day with no
     * interval produces no row and so reads back as closed (Requirement 32.5).
     *
     * @param  class-string<OpeningHour|BranchHour>  $modelClass
     */
    private function replace(string $modelClass, string $ownerColumn, string $ownerId, WeeklySchedule $schedule): void
    {
        $modelClass::query()->where($ownerColumn, $ownerId)->delete();

        foreach (DayOfWeek::cases() as $day) {
            foreach ($schedule->intervalsOn($day) as $interval) {
                /** @var Model $row */
                $row = new $modelClass([
                    'id' => $this->ids->ulid(),
                    $ownerColumn => $ownerId,
                    'day_of_week' => $day->value,
                    'start_minute' => $interval->startMinute,
                    'end_minute' => $interval->endMinute,
                ]);

                $row->save();
            }
        }
    }
}
