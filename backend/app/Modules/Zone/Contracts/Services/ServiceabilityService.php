<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Shared\Domain\Coordinate;

/**
 * The Zone module's serviceability seam (Requirements 5.8, 5.9, 32.11), consumed
 * by the Catalog module for discovery (Requirement 32.8) and by the Order module
 * for fulfilling-branch selection (Requirement 32.9).
 *
 * A Restaurant's Serviceable_Area is the union of the Delivery_Zones assigned to
 * its Restaurant_Branches with an active flag of true; a branch with no assigned
 * zone contributes nothing and a Restaurant with no such zone has an empty
 * Serviceable_Area (Requirements 5.9, 32.11). Containment within that area is
 * boundary inclusive (Requirement 5.8).
 */
interface ServiceabilityService
{
    /**
     * True when $at lies inside the Restaurant's Serviceable_Area, boundary
     * inclusive; false when the Serviceable_Area is empty or excludes $at
     * (Requirements 5.8, 5.9).
     */
    public function isServiceable(string $restaurantId, Coordinate $at): bool;

    /**
     * The identifiers of the active Restaurant_Branches whose assigned
     * Delivery_Zones contain $at, in ascending identifier order so a caller can
     * break a distance tie deterministically (Requirements 5.8, 32.9).
     *
     * @return list<string>
     */
    public function servicingBranchIds(string $restaurantId, Coordinate $at): array;
}
