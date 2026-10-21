<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\HoursManagementService;
use App\Modules\Catalog\Domain\SubmittedSchedule;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Catalog\Repositories\HoursRepository;
use App\Shared\Domain\Exceptions\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Opening_Hours and Branch_Hours management with atomic replacement
 * (Requirements 11.7, 11.8, 32.5, 32.6, 40.8).
 *
 * The service holds the rules; the {@see HoursRepository} holds the queries. The
 * contract of each operation is the same: validate the submitted intervals,
 * authorize the actor against the owner, then replace the owner's complete
 * stored schedule with the submitted intervals in one transaction.
 *
 * Three guarantees matter here:
 *
 *  - *Interval rules reject before any write.* The submitted schedule is passed
 *    through {@see SubmittedSchedule::validate()} first, outside the
 *    transaction. A violation raises `invalid_hours` with one per-field detail
 *    per violating interval and the method returns without touching the
 *    database, so a rejected submission changes nothing (Requirement 32.6,
 *    11.8).
 *  - *Replacement is atomic.* The delete-all-then-insert-all runs inside one
 *    {@see DB::transaction()}; the owner row is locked first, so a concurrent
 *    replacement of the same owner cannot interleave, and the whole set of
 *    intervals is replaced all-or-nothing (Requirement 40.8, design Property 31).
 *  - *Authorization is the owner's.* A Restaurant_Operator may manage only its
 *    own Restaurant's and Restaurant_Branches' hours; an Administrator may
 *    manage any; every other Role is forbidden. A missing owner reads as the
 *    non-disclosing `restaurant_not_found` rather than revealing whether the
 *    identifier exists.
 */
final class HoursManagementServiceImplementation implements HoursManagementService
{
    public function __construct(private readonly HoursRepository $hours) {}

    public function replaceOpeningHours(ActorContext $actor, string $restaurantId, array $submitted): void
    {
        // Validate before opening the transaction: a rule violation is rejected
        // with invalid_hours and nothing is stored (Requirement 32.6, 11.8).
        $schedule = SubmittedSchedule::validate($submitted);

        DB::transaction(function () use ($actor, $restaurantId, $schedule): void {
            $restaurant = $this->hours->lockRestaurant($restaurantId);

            if ($restaurant === null) {
                throw $this->notFound($restaurantId);
            }

            $this->authorize($actor, $restaurant->id);

            $this->hours->replaceOpeningHours($restaurant->id, $schedule);
        });
    }

    public function replaceBranchHours(ActorContext $actor, string $branchId, array $submitted): void
    {
        $schedule = SubmittedSchedule::validate($submitted);

        DB::transaction(function () use ($actor, $branchId, $schedule): void {
            $branch = $this->hours->lockBranch($branchId);

            if ($branch === null) {
                throw $this->notFound($branchId);
            }

            // A Restaurant_Branch's hours are owned by its parent Restaurant, so
            // authorization is against the Restaurant_Branch's restaurant_id.
            $this->authorize($actor, $this->restaurantIdOf($branch));

            $this->hours->replaceBranchHours($branch->id, $schedule);
        });
    }

    /**
     * Permits an Administrator over any Restaurant and a Restaurant_Operator
     * over only its own, and forbids every other actor (design authorization
     * matrix; Requirement 2.3). The Role is the one the middleware already
     * narrowed the route to; this is the ownership half of the decision.
     */
    private function authorize(ActorContext $actor, string $restaurantId): void
    {
        if ($actor->hasRole('administrator')) {
            return;
        }

        if ($actor->hasRole('restaurant_operator') && $actor->restaurantId === $restaurantId) {
            return;
        }

        throw AuthorizationException::forbidden(['restaurant_id' => $restaurantId]);
    }

    /**
     * The parent Restaurant identifier of a Restaurant_Branch, read from the
     * `restaurant_id` the locked row carries.
     */
    private function restaurantIdOf(RestaurantBranch $branch): string
    {
        return $branch->restaurant_id;
    }

    /**
     * The non-disclosing 404 used for both a missing Restaurant and a missing
     * Restaurant_Branch: identical whether the owner is absent or belongs to
     * another Restaurant, so the response never reveals its existence.
     */
    private function notFound(string $ownerId): AuthorizationException
    {
        return AuthorizationException::notFound(
            'restaurant_not_found',
            'No matching Restaurant was found.',
            ['owner_id' => $ownerId],
        );
    }
}
