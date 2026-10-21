<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts\Services;

use App\Modules\Pricing\Domain\PriceBreakdown;
use App\Modules\Pricing\Domain\PricingRequest;

/**
 * The Pricing_Engine Service_Interface (Requirement 7).
 *
 * The interface exists because the engine is a Module_Boundary seam: the Cart
 * and Order modules depend on this contract, not on the implementation, and the
 * contract is the unit the pricing property tests pin a test double against
 * (Requirement 38.7 reason: Module_Boundary and test-double). The single method
 * turns a fully-assembled {@see PricingRequest} into an immutable
 * {@see PriceBreakdown}, computing every component in integer minor units with
 * one half-up rounding step per produced value, so identical inputs always
 * yield identical outputs (Requirement 7.4).
 */
interface PricingEngine
{
    /**
     * Produces the price breakdown for the supplied request.
     *
     * The breakdown is marked provisional when the request carries no
     * Delivery_Address (Requirement 7.12).
     */
    public function quote(PricingRequest $request): PriceBreakdown;
}
