<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Contracts\Services\PricingEngine;
use App\Modules\Pricing\Domain\PriceBreakdown;
use App\Modules\Pricing\Domain\PricingRequest;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * The Pricing_Engine (Requirement 7), a pure framework-free Domain component.
 *
 * It depends on nothing but the shared kernel's {@see Money} and {@see Decimal}
 * arithmetic: no database, no Eloquent, no clock, no configuration source. Every
 * input arrives inside the {@see PricingRequest} the caller assembled, so the
 * output is a deterministic function of the input (Requirement 7.4).
 *
 * The six components are produced in the fixed order the design states, each
 * through exactly one half-up rounding step at the point it is produced:
 *
 *  1. Line_Subtotal  = Σ quantity × (basePrice + Σ modifier deltas)   — exact (7.1, 34.2).
 *  2. Delivery_Fee   = base + perKm × min(distance, 20.000 km), clamped to [min, max] (7.2, 7.12).
 *  3. Service_Fee    = serviceFeeRate% of Line_Subtotal, clamped to [min, max] (7.9).
 *  4. Tax_Amount     = taxRate% of (Line_Subtotal + Delivery_Fee + Service_Fee) (7.10).
 *  5. Discount       = supplied value clamped to [0, Line_Subtotal + Delivery_Fee] (7.5–7.7).
 *  6. Order_Total    = Line_Subtotal + Delivery_Fee + Service_Fee + Tax_Amount − Discount, in [0, 99,999,999] (7.3).
 */
final class PricingEngineImplementation implements PricingEngine
{
    /**
     * The Requirement 7.2 distance cap: distances beyond 20.000 km are treated
     * as 20.000 km. Held as thousandths of a kilometre to match the 3-digit
     * distance scale, keeping the per-kilometre multiplication an exact integer
     * operation.
     */
    private const DISTANCE_CAP_THOUSANDTHS = 20000;

    /**
     * The fractional-digit scale distances carry (Requirement 7.2): three, which
     * is the scale {@see Coordinate::haversineKilometres()}
     * rounds to.
     */
    private const DISTANCE_SCALE = 3;

    public function quote(PricingRequest $request): PriceBreakdown
    {
        $currency = $request->currency();

        $lineSubtotal = $this->lineSubtotal($request, $currency);
        $deliveryFee = $this->deliveryFee($request, $currency);
        $serviceFee = $this->serviceFee($request, $lineSubtotal);
        $taxAmount = $this->taxAmount($request, $lineSubtotal, $deliveryFee, $serviceFee);
        $discountAmount = $this->discountAmount($request, $currency, $lineSubtotal, $deliveryFee);

        $orderTotal = $lineSubtotal
            ->plus($deliveryFee)
            ->plus($serviceFee)
            ->plus($taxAmount)
            ->minus($discountAmount);

        $orderTotal->assertWithinStorableRange();

        return new PriceBreakdown(
            lineSubtotal: $lineSubtotal,
            deliveryFee: $deliveryFee,
            serviceFee: $serviceFee,
            taxAmount: $taxAmount,
            discountAmount: $discountAmount,
            orderTotal: $orderTotal,
            provisional: ! $request->hasDeliveryAddress(),
        );
    }

    /**
     * Line_Subtotal: the exact sum of the available Cart_Lines (Requirement 7.1).
     *
     * The sum is a sum of integer minor-unit amounts, so it produces no value
     * that needs rounding. A Cart with no available line yields 0 minor units.
     * The summed subtotal is asserted non-negative: modifier deltas that drove a
     * unit price below zero are a caller error, not something the engine clamps.
     */
    private function lineSubtotal(PricingRequest $request, string $currency): Money
    {
        $subtotal = Money::zero($currency);

        foreach ($request->lines as $line) {
            $subtotal = $subtotal->plus($line->subtotal());
        }

        if ($subtotal->isNegative()) {
            throw new InvalidArgumentException(sprintf(
                'A Line_Subtotal cannot be negative; computed %d minor units.',
                $subtotal->minorUnits,
            ));
        }

        return $subtotal;
    }

    /**
     * Delivery_Fee (Requirements 7.2, 7.12).
     *
     * With no Delivery_Address the fee is the configured minimum and the quote
     * is provisional. Otherwise the fee is `base + perKm × min(distance, 20 km)`,
     * where the per-kilometre term is produced through one half-up division (the
     * single rounding step for this value), then the whole fee is clamped to the
     * configured `[minimum, maximum]` range.
     */
    private function deliveryFee(PricingRequest $request, string $currency): Money
    {
        $restaurant = $request->restaurant;

        if (! $request->hasDeliveryAddress()) {
            return $restaurant->deliveryFeeMinimum;
        }

        /** @var string $distance */
        $distance = $request->distanceKilometres;
        $distanceThousandths = min(
            Decimal::toScaledInt($distance, self::DISTANCE_SCALE),
            self::DISTANCE_CAP_THOUSANDTHS,
        );

        $perKilometreTerm = Money::ofMinorUnits(
            $this->multiplyRateHalfUp($restaurant->deliveryFeePerKilometre->minorUnits, $distanceThousandths),
            $currency,
        );

        return $restaurant->deliveryFeeBase
            ->plus($perKilometreTerm)
            ->clamp($restaurant->deliveryFeeMinimum, $restaurant->deliveryFeeMaximum);
    }

    /**
     * Service_Fee: the configured percentage of Line_Subtotal with one half-up
     * step, clamped to the configured absolute `[minimum, maximum]` bounds
     * (Requirement 7.9).
     */
    private function serviceFee(PricingRequest $request, Money $lineSubtotal): Money
    {
        $policy = $request->serviceFee;

        return $lineSubtotal
            ->percentHalfUp($policy->basisPoints)
            ->clamp($policy->minimum, $policy->maximum);
    }

    /**
     * Tax_Amount: the Restaurant tax rate applied half up to
     * `Line_Subtotal + Delivery_Fee + Service_Fee`, computed before any discount
     * is deducted (Requirement 7.10).
     */
    private function taxAmount(
        PricingRequest $request,
        Money $lineSubtotal,
        Money $deliveryFee,
        Money $serviceFee,
    ): Money {
        return $lineSubtotal
            ->plus($deliveryFee)
            ->plus($serviceFee)
            ->percentHalfUp($request->restaurant->taxRateBasisPoints);
    }

    /**
     * Discount_Amount: the supplied Promotion value clamped to the closed range
     * `[0, Line_Subtotal + Delivery_Fee]` (Requirements 7.5–7.7). A request with
     * no Promotion yields a discount of 0.
     */
    private function discountAmount(
        PricingRequest $request,
        string $currency,
        Money $lineSubtotal,
        Money $deliveryFee,
    ): Money {
        $requested = $request->discountAmount ?? Money::zero($currency);

        return $requested->clamp(
            Money::zero($currency),
            $lineSubtotal->plus($deliveryFee),
        );
    }

    /**
     * Multiplies a per-kilometre rate (minor units) by a distance in thousandths
     * of a kilometre, dividing by 1,000 with one half-up rounding step.
     *
     * This mirrors {@see Money::percentHalfUp()}: the multiplication is checked
     * for integer overflow, the quotient is taken by integer division, and the
     * result is rounded up when twice the remainder reaches the divisor — the
     * single rounding step that makes Delivery_Fee reproducible for a given
     * distance (Requirement 7.4). Both operands are non-negative here, so no
     * sign handling is needed.
     */
    private function multiplyRateHalfUp(int $ratePerKilometre, int $distanceThousandths): int
    {
        if ($ratePerKilometre < 0) {
            throw new InvalidArgumentException(sprintf(
                'A per-kilometre Delivery_Fee rate cannot be negative; received %d.',
                $ratePerKilometre,
            ));
        }

        $divisor = 1000;
        $product = $this->multiplyExactly($ratePerKilometre, $distanceThousandths);
        $whole = intdiv($product, $divisor);

        if (($product % $divisor) * 2 >= $divisor) {
            $whole++;
        }

        return $whole;
    }

    private function multiplyExactly(int $left, int $right): int
    {
        if ($left !== 0 && $right !== 0 && abs($left) > intdiv(PHP_INT_MAX, abs($right))) {
            throw new InvalidArgumentException(
                sprintf('Multiplying %d by %d overflows an integer.', $left, $right)
            );
        }

        return $left * $right;
    }
}
