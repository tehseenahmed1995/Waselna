<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;

/**
 * Everything a Promotion's eligibility conditions and discount computation need,
 * assembled by the caller from the Cart (its Restaurant, its Line_Subtotal, and
 * the owning Customer's order history) before a code is evaluated.
 *
 * The Pricing module owns Promotions but owns neither the Cart nor the Order, so
 * it takes this framework-free value object rather than a Cart or Order model:
 * the module that drives checkout assembles it from its own data and the
 * boundaries it already consumes, and the Promotion evaluation stays a pure
 * function of its input. This is the Promotion-side analogue of
 * {@see PricingRequest}.
 *
 * {@see $lineSubtotal} is the Cart's Line_Subtotal (Requirement 7.1), the base a
 * `percent` discount is computed against together with the Delivery_Fee, and the
 * value a minimum-subtotal condition is tested against. {@see $deliveryFee} is
 * the Delivery_Fee already computed by the {@see PricingEngine}; a `percent`
 * discount applies to `lineSubtotal + deliveryFee`, mirroring the Requirement
 * 7.7 clamp ceiling. {@see $restaurantId} scopes a Restaurant-specific
 * Promotion, and {@see $isFirstOrder} drives a first-order-only condition.
 */
final readonly class PromotionContext
{
    public string $currency;

    public function __construct(
        public string $restaurantId,
        public Money $lineSubtotal,
        public Money $deliveryFee,
        public bool $isFirstOrder,
    ) {
        $this->currency = $lineSubtotal->currency;

        if ($deliveryFee->currency !== $this->currency) {
            throw new \InvalidArgumentException(sprintf(
                'A Promotion context mixes currencies %s and %s.',
                $this->currency,
                $deliveryFee->currency,
            ));
        }
    }

    /**
     * The ceiling a `percent` discount is computed against and the Requirement
     * 7.7 clamp ceiling: `Line_Subtotal + Delivery_Fee`.
     */
    public function discountableBase(): Money
    {
        return $this->lineSubtotal->plus($this->deliveryFee);
    }
}
