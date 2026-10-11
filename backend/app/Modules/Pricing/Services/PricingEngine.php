<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Catalog\Models\Restaurant;
use App\Shared\Domain\Money;

/**
 * The Pricing_Engine (Requirement 7). Every component is computed in integer
 * minor units with exactly one half-up rounding step per produced value, so
 * identical inputs always yield an identical breakdown.
 *
 * Components:
 *  - Line_Subtotal: sum of line subtotals (exact, no rounding).
 *  - Delivery_Fee: the Restaurant base fee, clamped to the configured bounds.
 *  - Service_Fee: a percentage of the subtotal, one half-up step.
 *  - Tax_Amount: a percentage of the subtotal (before discount), one half-up step.
 *  - Order_Total: subtotal + delivery + service + tax - discount.
 */
final class PricingEngine
{
    /**
     * The service-fee rate in basis points (8% default). Read from
     * Platform_Configuration in a fuller build; fixed here for the demo path.
     */
    private const SERVICE_FEE_BP = 800;

    private const DELIVERY_FEE_MIN_MINOR = 0;

    private const DELIVERY_FEE_MAX_MINOR = 5000;

    /**
     * @param  int  $lineSubtotalMinor  the summed line subtotals in minor units
     * @return array<string, mixed>
     */
    public function quote(string $restaurantId, int $lineSubtotalMinor, int $discountMinor = 0): array
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()->find($restaurantId);
        $currency = $restaurant?->currency ?? 'USD';
        $taxRateBp = (int) ($restaurant?->tax_rate_bp ?? 0);
        $baseDeliveryFee = (int) ($restaurant?->base_delivery_fee ?? 0);

        $subtotal = Money::ofMinorUnits($lineSubtotalMinor, $currency);

        $deliveryFee = Money::ofMinorUnits($baseDeliveryFee, $currency)->clamp(
            Money::ofMinorUnits(self::DELIVERY_FEE_MIN_MINOR, $currency),
            Money::ofMinorUnits(self::DELIVERY_FEE_MAX_MINOR, $currency),
        );

        $serviceFee = $subtotal->percentHalfUp(self::SERVICE_FEE_BP);
        $taxAmount = $subtotal->percentHalfUp($taxRateBp);

        // Discount is clamped to 0..(subtotal + delivery fee).
        $discount = Money::ofMinorUnits($discountMinor, $currency)->clamp(
            Money::zero($currency),
            $subtotal->plus($deliveryFee),
        );

        $total = $subtotal
            ->plus($deliveryFee)
            ->plus($serviceFee)
            ->plus($taxAmount)
            ->minus($discount);

        if ($total->isNegative()) {
            $total = Money::zero($currency);
        }

        return [
            'currency' => $currency,
            'line_subtotal' => $subtotal->toArray(),
            'delivery_fee' => $deliveryFee->toArray(),
            'service_fee' => $serviceFee->toArray(),
            'tax_amount' => $taxAmount->toArray(),
            'discount_amount' => $discount->toArray(),
            'order_total' => $total->toArray(),
        ];
    }
}
