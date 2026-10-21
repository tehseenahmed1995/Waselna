<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts\Services;

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Domain\BranchCommand;
use App\Modules\Catalog\Domain\BranchView;
use App\Modules\Catalog\Domain\RestaurantProfileCommand;
use App\Modules\Catalog\Domain\RestaurantProfileView;
use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;

/**
 * Restaurant profile and Restaurant_Branch management for a Restaurant_Operator
 * or Administrator (task 7.6; Requirements 32.1, 32.2, 32.3, 32.11, 32.12,
 * 32.13, 32.14).
 *
 * One seam over one implementation, as Requirement 38 asks: the Controller and
 * tests depend on this interface. Every operation takes the acting Platform user
 * so the service, not the Controller, decides whether the actor may manage the
 * Restaurant — a Restaurant_Operator over its own Restaurant and an
 * Administrator over any (design authorization matrix; Requirement 33.3). A
 * missing or out-of-scope Restaurant or Restaurant_Branch reads as the
 * non-disclosing `restaurant_not_found` rather than revealing whether the
 * identifier exists.
 *
 * The IANA time-zone validity of Requirement 32.1 and 32.2, the at-most-10
 * cuisine-tag count, the at-most-20 Delivery_Zone count of Requirement 32.11, and
 * the 50-branch bound of Requirement 32.3 are enforced here; the character-length
 * bounds the Form_Request can express are enforced before the request reaches the
 * service. Each write runs inside one transaction that first locks the parent
 * Restaurant row with `SELECT ... FOR UPDATE`, so a count check cannot be raced
 * (Requirement 32.13).
 *
 * Scope note: this service owns the Restaurant_Branch active flag write
 * (Requirement 32.3). The `last_active_branch` guard that forbids deactivating
 * the only active Restaurant_Branch of a `published` Restaurant (Requirement
 * 32.4) and the Restaurant_Status lifecycle itself belong to the separate status
 * task and are not enforced here.
 */
interface RestaurantManagementService
{
    /**
     * Updates the profile of the Restaurant, leaving every unsubmitted field
     * unchanged and evaluating the Restaurant's Opening_Hours against the stored
     * time zone thereafter (Requirement 32.1).
     *
     * @throws ValidationException `validation_failed` where the time zone is not
     *                             a valid IANA identifier or a cuisine-tag or
     *                             count bound is broken (Requirement 32.12); the
     *                             stored profile is left unchanged.
     * @throws AuthorizationException non-disclosing `restaurant_not_found` where
     *                                the actor may not manage the Restaurant or it
     *                                does not exist.
     */
    public function updateProfile(ActorContext $actor, RestaurantProfileCommand $command): RestaurantProfileView;

    /**
     * The Restaurant's current profile projection (Requirement 32.1).
     *
     * @throws AuthorizationException non-disclosing `restaurant_not_found`.
     */
    public function viewProfile(ActorContext $actor, string $restaurantId): RestaurantProfileView;

    /**
     * Creates a Restaurant_Branch against the actor's Restaurant with the
     * coordinate pair resolved by the Zone module, the active flag set to true,
     * and no Branch_Hours interval; where the command carries no time zone the
     * Restaurant's time zone is applied (Requirement 32.2).
     *
     * @throws ValidationException `branch_limit_reached` where the Restaurant
     *                             already holds 50 Restaurant_Branches
     *                             (Requirement 32.13) or `validation_failed`
     *                             where the time zone is not a valid IANA
     *                             identifier (Requirement 32.12); nothing is
     *                             created.
     * @throws AddressNotResolvable where the Zone module returns no coordinate
     *                              within its budget (Requirement 32.14); nothing
     *                              is created.
     * @throws AuthorizationException non-disclosing `restaurant_not_found`.
     */
    public function createBranch(ActorContext $actor, BranchCommand $command): BranchView;

    /**
     * Updates a Restaurant_Branch of the actor's Restaurant, re-resolving the
     * coordinate pair from the submitted address and applying the time zone the
     * same way creation does (Requirements 32.2, 32.12, 32.14).
     *
     * @throws ValidationException `validation_failed` where the time zone is not
     *                             a valid IANA identifier; the stored
     *                             Restaurant_Branch is left unchanged.
     * @throws AddressNotResolvable where the submitted address does not resolve
     *                              (Requirement 32.14); the stored
     *                              Restaurant_Branch is left unchanged.
     * @throws AuthorizationException non-disclosing `restaurant_not_found`.
     */
    public function updateBranch(ActorContext $actor, BranchCommand $command): BranchView;

    /**
     * Sets a Restaurant_Branch's active flag, persisting the submitted value
     * (Requirement 32.3). An idempotent write of the already-stored value leaves
     * the Restaurant_Branch unchanged.
     *
     * @throws AuthorizationException non-disclosing `restaurant_not_found`.
     */
    public function setBranchActive(ActorContext $actor, string $restaurantId, string $branchId, bool $isActive): BranchView;

    /**
     * Replaces the Delivery_Zones assigned to a Restaurant_Branch of the actor's
     * Restaurant with $zoneIds, so the Restaurant_Branch's Serviceable_Area
     * becomes the union of exactly those zones; an empty list clears the
     * assignment (Requirement 32.11).
     *
     * @param  list<string>  $zoneIds
     *
     * @throws ValidationException `validation_failed` where more than 20 zones
     *                             are submitted or a submitted identifier matches
     *                             no Delivery_Zone of the Restaurant (Requirement
     *                             32.12); the stored assignment is left unchanged.
     * @throws AuthorizationException non-disclosing `restaurant_not_found`.
     */
    public function assignZones(ActorContext $actor, string $restaurantId, string $branchId, array $zoneIds): BranchView;
}
