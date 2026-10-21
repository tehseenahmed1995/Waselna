<?php

declare(strict_types=1);

// Feature: waselna, Property 24: Pricing is integer-exact, component-correct, and deterministic

/*
 * For any Cart of 0..50 Cart_Lines, any Restaurant pricing profile, any
 * Platform_Configuration Service_Fee policy, and any Delivery_Address (present
 * or absent), the Pricing_Engine produces a breakdown in which:
 *
 *   - Line_Subtotal equals the sum over the available Cart_Lines of
 *     quantity x (base Price + the selected Modifier_Option Price_Deltas);
 *   - Delivery_Fee equals base + per-kilometre rate x min(distance, 20.000 km),
 *     with the distance read at 3 fractional digits and the per-kilometre term
 *     taken with a single half-up step, clamped to [minimum, maximum];
 *   - Service_Fee equals the configured percentage of Line_Subtotal taken with
 *     a single half-up step, clamped to its configured [minimum, maximum];
 *   - Tax_Amount equals the configured tax rate applied half up to
 *     Line_Subtotal + Delivery_Fee + Service_Fee, before any discount;
 *   - Discount_Amount equals the supplied value clamped to
 *     [0, Line_Subtotal + Delivery_Fee];
 *   - Order_Total equals Line_Subtotal + Delivery_Fee + Service_Fee
 *     + Tax_Amount - Discount_Amount and lies in 0..99,999,999 minor units;
 *   - every component is an integer minor-unit amount carrying the Restaurant's
 *     ISO 4217 currency code;
 *   - where no Delivery_Address is selected the Delivery_Fee is the configured
 *     minimum and the breakdown is marked provisional; and
 *   - recomputing from identical inputs yields an identical breakdown.
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.8, 7.9, 7.10, 7.12, 34.2**
 *
 * The subject is the framework-free PricingEngineImplementation (task 12.2),
 * exercised as a pure Domain unit: no database, no container, no clock, no
 * configuration source. Each component is checked against an independent oracle
 * that recomputes the value the long way round from the same PricingRequest, so
 * agreement is meaningful rather than a restatement of the engine's own code.
 *
 * Generators are constrained so every assembled PricingRequest constructs and
 * every resulting Order_Total stays inside the storable 0..99,999,999 range:
 * line counts span 0 (empty Cart) through the 50-line bound, quantities span the
 * 1..20 bound, monetary magnitudes stay small enough that the summed total never
 * overflows the storable ceiling, Service_Fee and tax rates span their full
 * 0..25 and 0..30 percent ranges, distances straddle the 20.000 km cap, and the
 * Delivery_Address is present and absent across inputs so the provisional branch
 * is reached by construction.
 */

use App\Modules\Pricing\Domain\PriceBreakdown;
use App\Modules\Pricing\Domain\PricingLine;
use App\Modules\Pricing\Domain\PricingRequest;
use App\Modules\Pricing\Domain\RestaurantPricingProfile;
use App\Modules\Pricing\Domain\ServiceFeePolicy;
use App\Modules\Pricing\Services\PricingEngineImplementation;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\Money;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;

uses(TestTrait::class);

/**
 * The one currency every amount in a generated request shares. The property is
 * about arithmetic, not currency negotiation, so a single fixed ISO 4217 code
 * keeps every Money comparable while the engine still carries it through.
 */
const PRICING_CURRENCY = 'SAR';

/**
 * The Requirement 7.2 distance cap in whole kilometres: distances at or beyond
 * 20.000 km are priced as 20.000 km.
 */
const PRICING_DISTANCE_CAP_THOUSANDTHS = 20000;

/**
 * The fractional scale a distance carries (Requirement 7.2): three digits, the
 * scale Coordinate::haversineKilometres rounds to.
 */
const PRICING_DISTANCE_SCALE = 3;

/**
 * A Money amount in the shared currency from a bounded non-negative magnitude.
 */
function pricingMoney(int $minorUnits): Money
{
    return Money::ofMinorUnits($minorUnits, PRICING_CURRENCY);
}

/**
 * One PricingLine: a quantity in the documented 1..20 bound, a small
 * non-negative base Price, and 0..4 small non-negative modifier deltas. Deltas
 * are kept non-negative so the unit price never falls below zero (which the
 * engine treats as a caller error), while magnitudes stay small enough that the
 * fifty-line sum cannot approach the storable ceiling.
 *
 * @return ErisGenerator<PricingLine>
 */
function pricingLine(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): PricingLine {
            [$quantity, $base, $deltas] = $parts;

            $modifierDeltas = array_map(
                static fn (int $delta): Money => pricingMoney($delta),
                $deltas,
            );

            return new PricingLine($quantity, pricingMoney($base), $modifierDeltas);
        },
        Generators::tuple(
            // Quantity across the whole 1..20 bound, hitting both edges.
            Generators::choose(1, 20),
            // Base Price: 0 reached often, ceiling small enough to keep sums bounded.
            Generators::choose(0, 5000),
            // 0..4 modifier deltas, each a small non-negative amount. The count is
            // resolved to a concrete int first (Generators::vector needs a plain
            // int size, not a generator), then a fixed-size vector of that count
            // is built — the same bind-then-build shape pricingLines() uses.
            pricingModifierDeltas(),
        ),
    );
}

/**
 * 0..4 modifier delta magnitudes. The count is drawn and bound to a concrete int
 * before building the vector, so an empty delta list is a frequent, constructed
 * outcome and no generator is ever handed to Generators::vector as its size.
 *
 * @return ErisGenerator<list<int>>
 */
function pricingModifierDeltas(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(0, 4),
        static function (int $count): ErisGenerator {
            if ($count === 0) {
                return Generators::constant([]);
            }

            return Generators::vector($count, Generators::choose(0, 500));
        },
    );
}

/**
 * 0..50 PricingLines, reaching the empty Cart (Line_Subtotal 0) and the 50-line
 * bound. The candidate count is drawn directly so an empty list is a frequent,
 * constructed outcome rather than a rare one.
 *
 * @return ErisGenerator<list<PricingLine>>
 */
function pricingLines(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(0, 50),
        static function (int $count): ErisGenerator {
            if ($count === 0) {
                return Generators::constant([]);
            }

            return Generators::tuple(...array_fill(0, $count, pricingLine()));
        },
    );
}

/**
 * A Restaurant pricing profile with a valid Delivery_Fee range and a tax rate
 * across the full 0..30 percent (0..3000 basis points) span. The minimum and
 * maximum are ordered so the clamp invariant always holds, and the base and
 * per-kilometre rate stay small so a capped-distance fee cannot overflow.
 *
 * @return ErisGenerator<RestaurantPricingProfile>
 */
function pricingProfile(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): RestaurantPricingProfile {
            [$base, $perKm, $boundA, $boundB, $taxBasisPoints] = $parts;

            $minimum = min($boundA, $boundB);
            $maximum = max($boundA, $boundB);

            return new RestaurantPricingProfile(
                deliveryFeeBase: pricingMoney($base),
                deliveryFeePerKilometre: pricingMoney($perKm),
                deliveryFeeMinimum: pricingMoney($minimum),
                deliveryFeeMaximum: pricingMoney($maximum),
                taxRateBasisPoints: $taxBasisPoints,
            );
        },
        Generators::tuple(
            Generators::choose(0, 3000),
            Generators::choose(0, 1000),
            Generators::choose(0, 5000),
            Generators::choose(0, 5000),
            Generators::choose(0, RestaurantPricingProfile::MAX_TAX_RATE_BASIS_POINTS),
        ),
    );
}

/**
 * A Service_Fee policy across the full 0..25 percent (0..2500 basis points) span
 * with an ordered absolute [minimum, maximum] bound.
 *
 * @return ErisGenerator<ServiceFeePolicy>
 */
function pricingServiceFeePolicy(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): ServiceFeePolicy {
            [$basisPoints, $boundA, $boundB] = $parts;

            $minimum = min($boundA, $boundB);
            $maximum = max($boundA, $boundB);

            return new ServiceFeePolicy(
                basisPoints: $basisPoints,
                minimum: pricingMoney($minimum),
                maximum: pricingMoney($maximum),
            );
        },
        Generators::tuple(
            Generators::choose(0, ServiceFeePolicy::MAX_SERVICE_FEE_BASIS_POINTS),
            Generators::choose(0, 3000),
            Generators::choose(0, 3000),
        ),
    );
}

/**
 * A distance string at 3 fractional digits, or null (no Delivery_Address). The
 * thousandths span straddles the 20.000 km cap on both sides, and null is drawn
 * often so the provisional branch is reached by construction.
 *
 * @return ErisGenerator<string|null>
 */
function pricingDistance(): ErisGenerator
{
    return Generators::map(
        static function (?int $thousandths): ?string {
            if ($thousandths === null) {
                return null;
            }

            $whole = intdiv($thousandths, 1000);
            $fraction = $thousandths % 1000;

            return sprintf('%d.%03d', $whole, $fraction);
        },
        // -1 is the sentinel for "no Delivery_Address"; 0..40000 thousandths of a
        // kilometre straddle the 20.000 km (20000-thousandth) cap generously.
        Generators::map(
            static fn (int $value): ?int => $value < 0 ? null : $value,
            Generators::choose(-1, 40000),
        ),
    );
}

/**
 * The optional Promotion discount: null, or a non-negative magnitude that may
 * exceed the clamp ceiling so the clamp is exercised in both directions.
 *
 * @return ErisGenerator<int|null>
 */
function pricingDiscount(): ErisGenerator
{
    return Generators::map(
        static fn (int $value): ?int => $value < 0 ? null : $value,
        Generators::choose(-1, 200000),
    );
}

/**
 * The independent oracle: recompute the whole breakdown from the request without
 * calling the engine, mirroring Requirement 7 step by step so agreement is a
 * genuine cross-check.
 *
 * @return array{
 *     lineSubtotal:int, deliveryFee:int, serviceFee:int, taxAmount:int,
 *     discountAmount:int, orderTotal:int, provisional:bool
 * }
 */
function pricingOracle(PricingRequest $request): array
{
    $profile = $request->restaurant;
    $policy = $request->serviceFee;

    // 1. Line_Subtotal: exact sum of quantity x (base + deltas).
    $lineSubtotal = 0;
    foreach ($request->lines as $line) {
        $unit = $line->basePrice->minorUnits;
        foreach ($line->modifierDeltas as $delta) {
            $unit += $delta->minorUnits;
        }
        $lineSubtotal += $unit * $line->quantity;
    }

    $hasAddress = $request->distanceKilometres !== null;

    // 2. Delivery_Fee.
    if (! $hasAddress) {
        $deliveryFee = $profile->deliveryFeeMinimum->minorUnits;
    } else {
        $distanceThousandths = min(
            Decimal::toScaledInt($request->distanceKilometres, PRICING_DISTANCE_SCALE),
            PRICING_DISTANCE_CAP_THOUSANDTHS,
        );

        $perKilometreTerm = pricingHalfUpDivide(
            $profile->deliveryFeePerKilometre->minorUnits * $distanceThousandths,
            1000,
        );

        $deliveryFee = pricingClamp(
            $profile->deliveryFeeBase->minorUnits + $perKilometreTerm,
            $profile->deliveryFeeMinimum->minorUnits,
            $profile->deliveryFeeMaximum->minorUnits,
        );
    }

    // 3. Service_Fee: percent of Line_Subtotal, half up, clamped.
    $serviceFee = pricingClamp(
        pricingPercentHalfUp($lineSubtotal, $policy->basisPoints),
        $policy->minimum->minorUnits,
        $policy->maximum->minorUnits,
    );

    // 4. Tax_Amount: tax rate half up over subtotal + delivery + service.
    $taxAmount = pricingPercentHalfUp(
        $lineSubtotal + $deliveryFee + $serviceFee,
        $profile->taxRateBasisPoints,
    );

    // 5. Discount: supplied value clamped to [0, subtotal + delivery].
    $requestedDiscount = $request->discountAmount?->minorUnits ?? 0;
    $discountAmount = pricingClamp($requestedDiscount, 0, $lineSubtotal + $deliveryFee);

    // 6. Order_Total.
    $orderTotal = $lineSubtotal + $deliveryFee + $serviceFee + $taxAmount - $discountAmount;

    return [
        'lineSubtotal' => $lineSubtotal,
        'deliveryFee' => $deliveryFee,
        'serviceFee' => $serviceFee,
        'taxAmount' => $taxAmount,
        'discountAmount' => $discountAmount,
        'orderTotal' => $orderTotal,
        'provisional' => ! $hasAddress,
    ];
}

/**
 * Half-up percentage of a non-negative amount by integer basis points, computed
 * independently of Money::percentHalfUp.
 */
function pricingPercentHalfUp(int $amount, int $basisPoints): int
{
    return pricingHalfUpDivide($amount * $basisPoints, 10000);
}

/**
 * Integer division of a non-negative numerator by a positive divisor, rounding
 * half up. The property's generators keep both operands non-negative.
 */
function pricingHalfUpDivide(int $numerator, int $divisor): int
{
    $whole = intdiv($numerator, $divisor);

    if (($numerator % $divisor) * 2 >= $divisor) {
        $whole++;
    }

    return $whole;
}

/**
 * Clamp an integer to the inclusive [$minimum, $maximum] range.
 */
function pricingClamp(int $value, int $minimum, int $maximum): int
{
    if ($value < $minimum) {
        return $minimum;
    }

    if ($value > $maximum) {
        return $maximum;
    }

    return $value;
}

it('produces a component-correct breakdown that matches an independent oracle for every input', function (): void {
    $this->limitTo(100)->forAll(
        pricingLines(),
        pricingProfile(),
        pricingServiceFeePolicy(),
        pricingDistance(),
        pricingDiscount(),
    )->then(function (
        array $lines,
        RestaurantPricingProfile $profile,
        ServiceFeePolicy $policy,
        ?string $distance,
        ?int $discount,
    ): void {
        $request = new PricingRequest(
            lines: $lines,
            restaurant: $profile,
            serviceFee: $policy,
            distanceKilometres: $distance,
            discountAmount: $discount === null ? null : pricingMoney($discount),
        );

        $expected = pricingOracle($request);

        // Only exercise inputs whose Order_Total is storable; an out-of-range
        // total is a precondition the caller is responsible for, not behaviour
        // of this component. The generators keep nearly every input in range.
        if ($expected['orderTotal'] < Money::MIN_STORABLE_MINOR_UNITS
            || $expected['orderTotal'] > Money::MAX_STORABLE_MINOR_UNITS) {
            return;
        }

        $breakdown = (new PricingEngineImplementation())->quote($request);

        // Every component matches the oracle, in minor units.
        expect($breakdown->lineSubtotal->minorUnits)->toBe($expected['lineSubtotal']);
        expect($breakdown->deliveryFee->minorUnits)->toBe($expected['deliveryFee']);
        expect($breakdown->serviceFee->minorUnits)->toBe($expected['serviceFee']);
        expect($breakdown->taxAmount->minorUnits)->toBe($expected['taxAmount']);
        expect($breakdown->discountAmount->minorUnits)->toBe($expected['discountAmount']);
        expect($breakdown->orderTotal->minorUnits)->toBe($expected['orderTotal']);

        // Order_Total lies in the storable 0..99,999,999 range (Requirement 7.3).
        expect($breakdown->orderTotal->minorUnits)
            ->toBeGreaterThanOrEqual(Money::MIN_STORABLE_MINOR_UNITS)
            ->toBeLessThanOrEqual(Money::MAX_STORABLE_MINOR_UNITS);

        // Delivery_Fee stays inside its configured bounds (Requirement 7.2).
        expect($breakdown->deliveryFee->minorUnits)
            ->toBeGreaterThanOrEqual($profile->deliveryFeeMinimum->minorUnits)
            ->toBeLessThanOrEqual($profile->deliveryFeeMaximum->minorUnits);

        // Service_Fee stays inside its configured bounds (Requirement 7.9).
        expect($breakdown->serviceFee->minorUnits)
            ->toBeGreaterThanOrEqual($policy->minimum->minorUnits)
            ->toBeLessThanOrEqual($policy->maximum->minorUnits);

        // Discount stays inside [0, Line_Subtotal + Delivery_Fee] (7.5-7.7).
        expect($breakdown->discountAmount->minorUnits)
            ->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual($expected['lineSubtotal'] + $expected['deliveryFee']);

        // Every component carries the Restaurant's single ISO 4217 currency.
        expect($breakdown->currency)->toBe(PRICING_CURRENCY);
        foreach ([
            $breakdown->lineSubtotal,
            $breakdown->deliveryFee,
            $breakdown->serviceFee,
            $breakdown->taxAmount,
            $breakdown->discountAmount,
            $breakdown->orderTotal,
        ] as $component) {
            expect($component->currency)->toBe(PRICING_CURRENCY);
        }

        // Provisional exactly when no Delivery_Address was supplied (7.12), and
        // then Delivery_Fee is the configured minimum.
        expect($breakdown->provisional)->toBe($distance === null);
        if ($distance === null) {
            expect($breakdown->deliveryFee->minorUnits)
                ->toBe($profile->deliveryFeeMinimum->minorUnits);
        }

        // Determinism: recomputing from the identical request yields an
        // identical breakdown (Requirement 7.4).
        $again = (new PricingEngineImplementation())->quote($request);
        expect($again->toArray())->toEqual($breakdown->toArray());
    });
});
