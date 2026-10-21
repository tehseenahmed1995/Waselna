<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * The pricing inputs a Restaurant configures, carried into the Pricing_Engine as
 * a framework-free value object rather than an Eloquent model.
 *
 * These are the Requirement 7.2 Delivery_Fee parameters — a flat base fee, a
 * per-kilometre rate, and the inclusive minimum and maximum the computed fee is
 * clamped to — together with the Requirement 7.10 tax rate carried as integer
 * basis points (1 percent = 100 basis points), bounded to the 0 to 30 percent
 * range Requirement 7.10 permits.
 *
 * Every monetary parameter is a {@see Money} amount in one currency, which also
 * fixes the currency of the whole breakdown (Requirement 7.8). The constructor
 * rejects a mixed-currency or inverted-bound profile so an invalid Restaurant
 * configuration surfaces before any amount is produced.
 */
final readonly class RestaurantPricingProfile
{
    /**
     * The widest tax rate Requirement 7.10 allows: 30 percent in basis points.
     */
    public const MAX_TAX_RATE_BASIS_POINTS = 3000;

    public string $currency;

    public function __construct(
        public Money $deliveryFeeBase,
        public Money $deliveryFeePerKilometre,
        public Money $deliveryFeeMinimum,
        public Money $deliveryFeeMaximum,
        public int $taxRateBasisPoints,
    ) {
        $this->currency = $deliveryFeeBase->currency;

        foreach ([$deliveryFeePerKilometre, $deliveryFeeMinimum, $deliveryFeeMaximum] as $amount) {
            if ($amount->currency !== $this->currency) {
                throw new InvalidArgumentException(sprintf(
                    'A Restaurant pricing profile mixes currencies %s and %s.',
                    $this->currency,
                    $amount->currency,
                ));
            }
        }

        if ($deliveryFeeMinimum->minorUnits > $deliveryFeeMaximum->minorUnits) {
            throw new InvalidArgumentException(sprintf(
                'A Restaurant minimum Delivery_Fee of %d cannot exceed its maximum of %d (Requirement 59.1).',
                $deliveryFeeMinimum->minorUnits,
                $deliveryFeeMaximum->minorUnits,
            ));
        }

        if ($taxRateBasisPoints < 0 || $taxRateBasisPoints > self::MAX_TAX_RATE_BASIS_POINTS) {
            throw new InvalidArgumentException(sprintf(
                'A tax rate must fall in the closed range 0 to %d basis points; received %d (Requirement 7.10).',
                self::MAX_TAX_RATE_BASIS_POINTS,
                $taxRateBasisPoints,
            ));
        }
    }
}
