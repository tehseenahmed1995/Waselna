<?php

declare(strict_types=1);

namespace App\Modules\Zone\Services;

use App\Modules\Zone\Contracts\Services\Containment;
use App\Modules\Zone\Contracts\Services\ServiceabilityService;
use App\Modules\Zone\Repositories\DeliveryZoneRepository;
use App\Shared\Domain\Coordinate;

/**
 * Computes serviceability as the union of the Delivery_Zones on a Restaurant's
 * active Restaurant_Branches (Requirements 5.8, 5.9, 32.11).
 *
 * A coordinate is serviceable when it is contained by at least one of those
 * zones, boundary inclusive. The indexed decision runs in the database through
 * the repository's `ST_Covers` query; the injected Containment implementation is
 * the swappable per-polygon seam that a property test pins to the in-memory
 * ray-cast evaluator so the two never diverge (design Property 18).
 *
 * A branch with no assigned zone contributes nothing to the union, and a
 * Restaurant with no active branch holding a zone has an empty Serviceable_Area
 * — both fall out of the query returning no covering row, so `isServiceable`
 * returns false and `servicingBranchIds` returns an empty list (Requirement
 * 5.9).
 */
final class DefaultServiceabilityService implements ServiceabilityService
{
    public function __construct(
        private readonly DeliveryZoneRepository $zones,
        private readonly Containment $containment,
    ) {}

    public function isServiceable(string $restaurantId, Coordinate $at): bool
    {
        if ($this->zones->restaurantCovers($restaurantId, $at)) {
            return true;
        }

        // The union is empty or excludes the point in the database; confirm with
        // the injected per-polygon evaluator over the same geometries so the two
        // containment implementations stay in agreement (Requirement 5.8).
        foreach ($this->zones->geometriesForActiveRestaurantBranches($restaurantId) as $geometry) {
            if ($this->containment->contains($geometry, $at)) {
                return true;
            }
        }

        return false;
    }

    public function servicingBranchIds(string $restaurantId, Coordinate $at): array
    {
        return $this->zones->servicingBranchIds($restaurantId, $at);
    }
}
