<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\PostalAddress;

/**
 * The Zone module's address-resolution seam (Requirements 5.6, 5.7), consumed by
 * the Account module when a Customer stores a Delivery_Address (Requirement 31)
 * and by the Catalog module when a Restaurant_Branch address is submitted
 * (Requirement 32.14).
 *
 * The seam is an interface — rather than the Backend_Layer_Chain's usual single
 * concrete class — because it has a documented second-implementation and
 * test-double requirement (design "Directory layout" notes): the initial phase
 * binds a local deterministic adapter and integrates no paid geocoding service
 * (Requirements 23.8, 43.7), and a later phase may bind a networked adapter
 * behind the same contract without any caller change.
 *
 * A resolved coordinate always falls in the ranges Requirement 5.6 fixes —
 * latitude in -90 to 90, longitude in -180 to 180, at most 7 fractional digits —
 * which the {@see Coordinate} return type enforces at construction.
 */
interface AddressResolver
{
    /**
     * The resolution budget in milliseconds. An adapter that cannot return a
     * coordinate within this window raises {@see AddressNotResolvable} exactly as
     * it does for a no-match address (Requirements 5.6, 5.7).
     */
    public const RESOLUTION_BUDGET_MILLISECONDS = 3000;

    /**
     * Resolves $address to a coordinate pair within the
     * {@see self::RESOLUTION_BUDGET_MILLISECONDS} budget.
     *
     * @throws AddressNotResolvable when the address matches nothing or the budget
     *                              elapses before a coordinate is produced; the
     *                              caller stores nothing in that case
     *                              (Requirement 5.7).
     */
    public function resolve(PostalAddress $address): Coordinate;
}
