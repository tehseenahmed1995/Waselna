<?php

declare(strict_types=1);

namespace App\Modules\Zone\Services;

use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Modules\Zone\Repositories\DeliveryZoneRepository;

/**
 * The Zone module's implementation of the {@see ZoneAssignmentDirectory}
 * boundary (Requirements 32.11, 32.12).
 *
 * It is a thin adapter over the {@see DeliveryZoneRepository}, the one place in
 * the Zone module that queries the `delivery_zones` and `delivery_zone_branch`
 * tables. The boundary exists so the Catalog module can read a Restaurant's zone
 * identifiers and replace a Restaurant_Branch's assignments without reaching
 * into a Zone Eloquent_Model itself (Requirement 22.8); the repository already
 * performs the assignment replacement in one transaction, which is what makes
 * the write all-or-nothing (Requirement 40.8).
 */
final class DeliveryZoneAssignmentDirectory implements ZoneAssignmentDirectory
{
    public function __construct(private readonly DeliveryZoneRepository $zones) {}

    public function zoneIdsForRestaurant(string $restaurantId): array
    {
        return $this->zones->zoneIdsForRestaurant($restaurantId);
    }

    public function zoneIdsForBranch(string $branchId): array
    {
        return $this->zones->zoneIdsForBranch($branchId);
    }

    public function replaceBranchAssignments(string $branchId, array $zoneIds): void
    {
        $this->zones->replaceBranchAssignments($branchId, $zoneIds);
    }

    public function anyZoneOnActiveBranch(string $restaurantId): bool
    {
        return $this->zones->anyZoneOnActiveBranch($restaurantId);
    }
}
