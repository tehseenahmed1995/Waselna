<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain\Exceptions;

use App\Shared\Domain\Exceptions\DomainException;
use App\Shared\Domain\Exceptions\ErrorCatalogue;

/**
 * Raised when a resolved coordinate pair lies outside the Serviceable_Area of
 * the Restaurant in the Customer's Cart, or that Restaurant has an empty
 * Serviceable_Area because no active Restaurant_Branch holds an assigned
 * Delivery_Zone (Requirements 5.9, 5.10, 32.14).
 *
 * It is a {@see DomainException} carrying the 422 catalogue code
 * `address_not_serviceable`, so the single exception handler renders it through
 * the one error-rendering pipeline; the Cart contents are left unchanged
 * (Requirement 5.10) because the rejection is thrown before any write.
 */
final class AddressNotServiceable extends DomainException
{
    private const CODE = 'address_not_serviceable';

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(string $message, array $context = [])
    {
        ErrorCatalogue::assertCodeHasStatus(self::CODE, [422]);

        parent::__construct(self::CODE, $message, [], $context);
    }

    /**
     * The resolved coordinate is not inside the Restaurant's Serviceable_Area —
     * covering the empty-Serviceable_Area case of Requirement 5.9 and the
     * outside-the-area case of Requirement 5.10, which share one code and one
     * outcome. The Restaurant identifier is logged, never returned in the body.
     */
    public static function forRestaurant(string $restaurantId): self
    {
        return new self(
            'The submitted address is outside the delivery area for this order.',
            ['restaurant_id' => $restaurantId],
        );
    }
}
