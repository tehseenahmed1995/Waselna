<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * Everything the Pricing_Engine needs to produce one {@see PriceBreakdown},
 * assembled by the caller from the Cart, the fulfilling Restaurant_Branch, and
 * Platform_Configuration.
 *
 * The engine takes no Cart, no Eloquent model, and no configuration source: it
 * takes this value object. That is what makes it a pure, framework-free Domain
 * component whose output depends only on its input (Requirement 7.4).
 *
 * Availability is resolved before this request is built: only the available
 * Cart_Lines appear in {@see $lines} (Requirement 7.1). An empty line list is
 * valid and yields a Line_Subtotal of 0.
 *
 * {@see $distanceKilometres} is the straight-line distance between the selected
 * Restaurant_Branch and the Delivery_Address, already rounded half up to 3
 * fractional digits by {@see Coordinate::haversineKilometres()}.
 * When it is null no Delivery_Address is selected, so the breakdown is
 * provisional and Delivery_Fee is the configured minimum (Requirement 7.12).
 *
 * {@see $discountAmount} is the Promotion discount value, already evaluated by
 * the Promotion service; the engine clamps it (Requirements 7.5–7.7) but never
 * evaluates eligibility.
 */
final readonly class PricingRequest
{
    /**
     * @param  list<PricingLine>  $lines  the available Cart_Lines, 0 to 50 of them
     */
    public function __construct(
        public array $lines,
        public RestaurantPricingProfile $restaurant,
        public ServiceFeePolicy $serviceFee,
        public ?string $distanceKilometres = null,
        public ?Money $discountAmount = null,
    ) {
        if (count($lines) > 50) {
            throw new InvalidArgumentException(sprintf(
                'A Cart holds at most 50 Cart_Lines; received %d (Requirement 7.1).',
                count($lines),
            ));
        }

        $currency = $restaurant->currency;

        foreach ($lines as $line) {
            if ($line->currency() !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'A Cart_Line priced in %s cannot be quoted for a Restaurant in %s.',
                    $line->currency(),
                    $currency,
                ));
            }
        }

        if ($serviceFee->currency !== $currency) {
            throw new InvalidArgumentException(sprintf(
                'A Service_Fee policy in %s cannot price a Cart in %s.',
                $serviceFee->currency,
                $currency,
            ));
        }

        if ($discountAmount !== null && $discountAmount->currency !== $currency) {
            throw new InvalidArgumentException(sprintf(
                'A discount in %s cannot price a Cart in %s.',
                $discountAmount->currency,
                $currency,
            ));
        }

        if ($distanceKilometres !== null) {
            if (! Decimal::isDecimal($distanceKilometres)) {
                throw new InvalidArgumentException(sprintf(
                    'A distance must be a decimal number of kilometres; received "%s".',
                    $distanceKilometres,
                ));
            }

            if (Decimal::compare($distanceKilometres, '0', 3) < 0) {
                throw new InvalidArgumentException(sprintf(
                    'A distance cannot be negative; received "%s".',
                    $distanceKilometres,
                ));
            }
        }
    }

    /**
     * The currency every amount in the resulting breakdown is denominated in,
     * taken from the Restaurant profile (Requirement 7.8).
     */
    public function currency(): string
    {
        return $this->restaurant->currency;
    }

    /**
     * Whether a Delivery_Address is selected. When false the breakdown is
     * provisional and Delivery_Fee is the configured minimum (Requirement 7.12).
     */
    public function hasDeliveryAddress(): bool
    {
        return $this->distanceKilometres !== null;
    }
}
