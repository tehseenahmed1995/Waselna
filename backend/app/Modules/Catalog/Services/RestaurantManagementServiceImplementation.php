<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\RestaurantManagementService;
use App\Modules\Catalog\Domain\BranchCommand;
use App\Modules\Catalog\Domain\BranchView;
use App\Modules\Catalog\Domain\RestaurantProfileCommand;
use App\Modules\Catalog\Domain\RestaurantProfileView;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Catalog\Repositories\RestaurantManagementRepository;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant profile and Restaurant_Branch management (task 7.6; Requirements
 * 32.1, 32.2, 32.3, 32.11, 32.12, 32.13, 32.14).
 *
 * The service holds the rules; the {@see RestaurantManagementRepository} holds
 * the Catalog-table queries, and the {@see AddressResolver} and
 * {@see ZoneAssignmentDirectory} seams hold everything the Zone module owns. A
 * handful of guarantees run through every write:
 *
 *  - *Authorization is the owner's.* A Restaurant_Operator may manage only its
 *    own Restaurant; an Administrator may manage any; every other Role never
 *    reaches here because the route gate narrows it, and ownership is the half
 *    this service decides (Requirement 33.3). A missing or out-of-scope
 *    Restaurant or Restaurant_Branch reads as the non-disclosing
 *    `restaurant_not_found`.
 *  - *Counts are race-free.* Every write opens one {@see DB::transaction()} and
 *    locks the parent Restaurant row first with `SELECT ... FOR UPDATE`
 *    (Requirement 32.13), so the 50-branch bound of Requirement 32.3 cannot be
 *    raced by two concurrent creations.
 *  - *Rejections change nothing.* The IANA time-zone check, the 50-branch bound,
 *    the 20-zone bound, and the zone-ownership check all throw before the write
 *    they guard, and address resolution runs before the transaction opens, so a
 *    rejected submission leaves the stored Restaurant, Restaurant_Branch, and
 *    Serviceable_Area untouched (Requirements 32.12, 32.13, 32.14).
 *  - *Time comes from the injected {@see Clock}.* The `updated_at` instant of
 *    every write is the Clock's UTC instant, so ordering is deterministic under
 *    test.
 *
 * Scope note: the Restaurant_Branch active flag is written here (Requirement
 * 32.3), and deactivating the last active Restaurant_Branch of a published
 * Restaurant is refused with `last_active_branch` (Requirement 32.4). The
 * broader Restaurant_Status lifecycle (transitions, publication prerequisites)
 * belongs to the separate status task.
 */
final class RestaurantManagementServiceImplementation implements RestaurantManagementService
{
    /** The maximum Restaurant_Branches per Restaurant, inactive included (Requirement 32.3). */
    public const MAX_BRANCHES = 50;

    /** The maximum Delivery_Zones assignable to one Restaurant_Branch (Requirement 32.11). */
    public const MAX_ZONES_PER_BRANCH = 20;

    public function __construct(
        private readonly RestaurantManagementRepository $restaurants,
        private readonly AddressResolver $resolver,
        private readonly ZoneAssignmentDirectory $zones,
        private readonly Clock $clock,
    ) {}

    public function updateProfile(ActorContext $actor, RestaurantProfileCommand $command): RestaurantProfileView
    {
        // The IANA validity Requirement 32.12 names cannot be expressed by the
        // Form_Request's string rule; reject before the transaction so nothing
        // is written.
        $this->assertValidTimeZone($command->timeZone, 'time_zone');

        return DB::transaction(function () use ($actor, $command): RestaurantProfileView {
            $restaurant = $this->lockOwnedRestaurant($actor, $command->restaurantId);

            $restaurant->fill([
                'name' => $command->name,
                'description' => $command->description,
                'currency' => $command->currency,
                'tax_rate_bp' => $command->taxRateBasisPoints,
                'base_delivery_fee' => $command->baseDeliveryFeeMinor,
                'time_zone' => $command->timeZone,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->restaurants->saveRestaurant($restaurant);

            // Cuisine tags are a separate owned table; the complete set is
            // replaced so a removed tag disappears (Requirement 32.1).
            $this->restaurants->replaceCuisineTags($restaurant->id, $command->cuisineTags);

            return RestaurantProfileView::fromModel(
                $restaurant,
                $this->restaurants->cuisineTagsFor($restaurant->id),
            );
        });
    }

    public function viewProfile(ActorContext $actor, string $restaurantId): RestaurantProfileView
    {
        return DB::transaction(function () use ($actor, $restaurantId): RestaurantProfileView {
            $restaurant = $this->lockOwnedRestaurant($actor, $restaurantId);

            return RestaurantProfileView::fromModel(
                $restaurant,
                $this->restaurants->cuisineTagsFor($restaurant->id),
            );
        });
    }

    public function createBranch(ActorContext $actor, BranchCommand $command): BranchView
    {
        $this->assertOptionalTimeZone($command->timeZone);

        // Resolve before opening the transaction: a no-match address is rejected
        // with nothing created (Requirements 5.7, 32.14).
        $coordinate = $this->resolver->resolve($command->toPostalAddress());

        return DB::transaction(function () use ($actor, $command, $coordinate): BranchView {
            $restaurant = $this->lockOwnedRestaurant($actor, $command->restaurantId);

            // The 50-branch bound is checked under the parent lock so two
            // concurrent creations cannot both pass (Requirements 32.3, 32.13).
            if ($this->restaurants->countBranches($restaurant->id) >= self::MAX_BRANCHES) {
                throw ValidationException::because(
                    'branch_limit_reached',
                    'A Restaurant holds at most '.self::MAX_BRANCHES.' Restaurant_Branches.',
                );
            }

            $now = $this->clock->nowUtc();
            $branch = new RestaurantBranch([
                'id' => $this->restaurants->newBranchId(),
                'restaurant_id' => $restaurant->id,
                'name' => $command->name,
                'address' => $this->composeAddress($command),
                'latitude' => $coordinate->latitude,
                'longitude' => $coordinate->longitude,
                // Absent time zone falls back to the Restaurant's (Requirement 32.2).
                'time_zone' => $command->timeZone ?? $restaurant->time_zone,
                // A newly created Restaurant_Branch is always active (Requirement 32.2).
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->restaurants->saveBranch($branch);

            return BranchView::fromModel($branch, []);
        });
    }

    public function updateBranch(ActorContext $actor, BranchCommand $command): BranchView
    {
        $this->assertOptionalTimeZone($command->timeZone);

        // Re-resolve before the transaction so an updated address that no longer
        // resolves is rejected with nothing changed (Requirement 32.14).
        $coordinate = $this->resolver->resolve($command->toPostalAddress());

        return DB::transaction(function () use ($actor, $command, $coordinate): BranchView {
            $restaurant = $this->lockOwnedRestaurant($actor, $command->restaurantId);
            $branchId = (string) $command->branchId;

            $branch = $this->restaurants->findBranchOwned($restaurant->id, $branchId);
            if ($branch === null) {
                throw $this->notFound($branchId);
            }

            $branch->fill([
                'name' => $command->name,
                'address' => $this->composeAddress($command),
                'latitude' => $coordinate->latitude,
                'longitude' => $coordinate->longitude,
                'time_zone' => $command->timeZone ?? $restaurant->time_zone,
                'updated_at' => $this->clock->nowUtc(),
            ]);
            $this->restaurants->saveBranch($branch);

            return BranchView::fromModel($branch, $this->zones->zoneIdsForBranch($branch->id));
        });
    }

    public function setBranchActive(ActorContext $actor, string $restaurantId, string $branchId, bool $isActive): BranchView
    {
        return DB::transaction(function () use ($actor, $restaurantId, $branchId, $isActive): BranchView {
            $restaurant = $this->lockOwnedRestaurant($actor, $restaurantId);

            $branch = $this->restaurants->findBranchOwned($restaurant->id, $branchId);
            if ($branch === null) {
                throw $this->notFound($branchId);
            }

            // A published Restaurant must always retain at least one active
            // Restaurant_Branch: deactivating the last active one is refused with
            // last_active_branch and the Restaurant_Branch is left unchanged
            // (Requirement 32.4). The count is read under the parent lock taken
            // by lockOwnedRestaurant, so two concurrent deactivations cannot both
            // pass. A Restaurant in any other Restaurant_Status may reach zero
            // active Restaurant_Branches.
            if (! $isActive
                && (bool) $branch->is_active === true
                && $restaurant->status === 'published'
                && $this->restaurants->countActiveBranches($restaurant->id) === 1) {
                throw ConflictException::because(
                    'last_active_branch',
                    'A published Restaurant must retain at least one active Restaurant_Branch.',
                    ['branch_id' => $branchId],
                );
            }

            // The submitted value is persisted (Requirement 32.3); the
            // updated_at instant moves only when the flag actually changes, so an
            // idempotent write leaves the row unchanged.
            if ((bool) $branch->is_active !== $isActive) {
                $branch->fill(['is_active' => $isActive, 'updated_at' => $this->clock->nowUtc()]);
                $this->restaurants->saveBranch($branch);
            }

            return BranchView::fromModel($branch, $this->zones->zoneIdsForBranch($branch->id));
        });
    }

    public function assignZones(ActorContext $actor, string $restaurantId, string $branchId, array $zoneIds): BranchView
    {
        return DB::transaction(function () use ($actor, $restaurantId, $branchId, $zoneIds): BranchView {
            $restaurant = $this->lockOwnedRestaurant($actor, $restaurantId);

            $branch = $this->restaurants->findBranchOwned($restaurant->id, $branchId);
            if ($branch === null) {
                throw $this->notFound($branchId);
            }

            /** @var list<string> $submitted */
            $submitted = array_values(array_unique(array_map('strval', $zoneIds)));

            // At most 20 Delivery_Zones may be assigned (Requirement 32.11); more
            // is rejected with validation_failed and nothing changed (32.12).
            if (count($submitted) > self::MAX_ZONES_PER_BRANCH) {
                throw ValidationException::forField(
                    'zone_ids',
                    'max',
                    'At most '.self::MAX_ZONES_PER_BRANCH.' Delivery_Zones may be assigned to a Restaurant_Branch.',
                );
            }

            // Every submitted zone must belong to the Restaurant; an identifier
            // matching no Delivery_Zone of the Restaurant is rejected with
            // validation_failed and nothing changed (Requirement 32.12). The
            // ownership set is read through the Zone boundary, never a Zone table.
            $owned = $this->zones->zoneIdsForRestaurant($restaurant->id);
            $unknown = array_values(array_diff($submitted, $owned));
            if ($unknown !== []) {
                throw ValidationException::failed(
                    ['zone_ids' => ['One or more Delivery_Zones do not belong to this Restaurant.']],
                );
            }

            $this->zones->replaceBranchAssignments($branch->id, $submitted);

            return BranchView::fromModel($branch, $submitted);
        });
    }

    /**
     * Locks the actor's own Restaurant row for the transaction and returns it,
     * raising the non-disclosing 404 where it does not exist or belongs to
     * another Restaurant_Operator. The lock is the race guard for every count
     * check (Requirement 32.13).
     */
    private function lockOwnedRestaurant(ActorContext $actor, string $restaurantId): Restaurant
    {
        $restaurant = $this->restaurants->lockRestaurant($restaurantId);

        if ($restaurant === null || ! $this->mayManage($actor, $restaurant->id)) {
            throw $this->notFound($restaurantId);
        }

        return $restaurant;
    }

    /**
     * Permits an Administrator over any Restaurant and a Restaurant_Operator over
     * only its own (design authorization matrix; Requirement 33.3). The Role was
     * already narrowed by the route gate; this is the ownership half. A denied
     * actor is folded into the non-disclosing 404 by the caller rather than a
     * 403, so a Restaurant_Operator cannot probe another Restaurant's existence.
     */
    private function mayManage(ActorContext $actor, string $restaurantId): bool
    {
        if ($actor->hasRole('administrator')) {
            return true;
        }

        return $actor->hasRole('restaurant_operator') && $actor->restaurantId === $restaurantId;
    }

    /**
     * Builds the stored single-line address from the submitted postal parts, the
     * same parts handed to the resolver, so the stored address and the resolved
     * coordinate describe one place.
     */
    private function composeAddress(BranchCommand $command): string
    {
        return implode(', ', $command->toPostalAddress()->parts());
    }

    /**
     * Rejects a time zone that is not a valid IANA identifier with
     * `validation_failed` naming the field (Requirement 32.12).
     */
    private function assertValidTimeZone(string $timeZone, string $field): void
    {
        if (! in_array($timeZone, timezone_identifiers_list(), true)) {
            throw ValidationException::forField(
                $field,
                'timezone',
                'The time zone must be a valid IANA time zone identifier.',
            );
        }
    }

    /**
     * Validates the optional Restaurant_Branch time zone only where one was
     * submitted; an absent time zone is valid and falls back to the Restaurant's
     * (Requirement 32.2).
     */
    private function assertOptionalTimeZone(?string $timeZone): void
    {
        if ($timeZone !== null) {
            $this->assertValidTimeZone($timeZone, 'time_zone');
        }
    }

    /**
     * The non-disclosing 404 of Requirement 32.12: identical whether the
     * Restaurant or Restaurant_Branch does not exist or belongs to another
     * Restaurant_Operator, so the response never reveals its existence.
     */
    private function notFound(string $id): AuthorizationException
    {
        return AuthorizationException::notFound(
            'restaurant_not_found',
            'No matching Restaurant was found.',
            ['id' => $id],
        );
    }
}
