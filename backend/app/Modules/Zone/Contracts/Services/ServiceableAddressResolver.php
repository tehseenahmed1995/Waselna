<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Modules\Zone\Domain\Exceptions\AddressNotServiceable;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\PostalAddress;

/**
 * The Zone module's serviceability-gated resolution seam (Requirements 5.6, 5.7,
 * 5.9, 5.10, 32.14), consumed by the Order module at checkout, where a submitted
 * Delivery_Address must both resolve to a coordinate and fall inside the
 * Serviceable_Area of the Restaurant in the Customer's Cart (design Property 19).
 *
 * It composes the two lower seams — {@see AddressResolver} and
 * {@see ServiceabilityService} — so a caller gets one call and one place where
 * the two documented rejections are raised, both flowing through the single
 * error-rendering pipeline. Neither rejection writes anything, because both are
 * raised before the caller performs any Delivery_Address or Cart write.
 */
interface ServiceableAddressResolver
{
    /**
     * Resolves $address and confirms it is serviceable by $restaurantId,
     * returning the resolved coordinate when both hold.
     *
     * @throws AddressNotResolvable when the address matches nothing or the
     *                              3,000-millisecond budget elapses
     *                              (Requirements 5.6, 5.7)
     * @throws AddressNotServiceable when the resolved coordinate is outside the
     *                               Restaurant's Serviceable_Area, including when
     *                               that area is empty (Requirements 5.9, 5.10)
     */
    public function resolveServiceable(string $restaurantId, PostalAddress $address): Coordinate;
}
