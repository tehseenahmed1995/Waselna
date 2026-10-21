<?php

declare(strict_types=1);

namespace App\Modules\Zone\Services;

use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Contracts\Services\ServiceabilityService;
use App\Modules\Zone\Contracts\Services\ServiceableAddressResolver;
use App\Modules\Zone\Domain\Exceptions\AddressNotServiceable;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\PostalAddress;

/**
 * Composes address resolution and serviceability into the single
 * serviceability-gated resolution the Order module calls at checkout
 * (Requirements 5.6, 5.7, 5.9, 5.10, 32.14; design Property 19).
 *
 * The order matters: resolution runs first, so an unresolvable address is
 * rejected with `address_not_resolvable` before serviceability is consulted
 * (Requirement 5.7). Only a resolved coordinate is tested against the
 * Restaurant's Serviceable_Area; an empty area or a coordinate outside it is
 * rejected with `address_not_serviceable` (Requirements 5.9, 5.10). Both
 * rejections are raised before any write, so nothing is stored or changed.
 */
final class DefaultServiceableAddressResolver implements ServiceableAddressResolver
{
    public function __construct(
        private readonly AddressResolver $resolver,
        private readonly ServiceabilityService $serviceability,
    ) {}

    public function resolveServiceable(string $restaurantId, PostalAddress $address): Coordinate
    {
        // Resolution first; an unresolvable address raises AddressNotResolvable
        // and never reaches the serviceability check (Requirement 5.7).
        $coordinate = $this->resolver->resolve($address);

        if (! $this->serviceability->isServiceable($restaurantId, $coordinate)) {
            throw AddressNotServiceable::forRestaurant($restaurantId);
        }

        return $coordinate;
    }
}
