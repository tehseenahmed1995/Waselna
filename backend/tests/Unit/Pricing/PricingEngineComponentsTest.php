<?php

declare(strict_types=1);

// Feature: waselna, task 12.6: unit tests for each Pricing_Engine component.

/*
 * Example-based unit tests for the framework-free Pricing_Engine (task 12.2;
 * Requirements 7.1-7.4, 7.8-7.10, 7.12, 34.2). Each test pins one component of
 * the breakdown with a hand-computed expected value. Pure Domain: no database,
 * no container, no clock. Helpers are prefixed `pe126`.
 */

use App\Modules\Pricing\Domain\PriceBreakdown;
use App\Modules\Pricing\Domain\PricingLine;
use App\Modules\Pricing\Domain\PricingRequest;
use App\Modules\Pricing\Domain\RestaurantPricingProfile;
use App\Modules\Pricing\Domain\ServiceFeePolicy;
use App\Modules\Pricing\Services\PricingEngineImplementation;
use App\Shared\Domain\Money;

const PE126_CURRENCY = 'USD';

function pe126Money(int $minor): Money
{
    return Money::ofMinorUnits($minor, PE126_CURRENCY);
}

function pe126Profile(int $base = 0, int $perKm = 0, int $min = 0, int $max = 99_999_999, int $taxBp = 0): RestaurantPricingProfile
{
    return new RestaurantPricingProfile(
        deliveryFeeBase: pe126Money($base),
        deliveryFeePerKilometre: pe126Money($perKm),
        deliveryFeeMinimum: pe126Money($min),
        deliveryFeeMaximum: pe126Money($max),
        taxRateBasisPoints: $taxBp,
    );
}

function pe126ServiceFee(int $bp = 0, int $min = 0, int $max = 99_999_999): ServiceFeePolicy
{
    return new ServiceFeePolicy(basisPoints: $bp, minimum: pe126Money($min), maximum: pe126Money($max));
}

/**
 * @param  list<PricingLine>  $lines
 */
function pe126Quote(array $lines, ?RestaurantPricingProfile $profile = null, ?ServiceFeePolicy $serviceFee = null, ?string $distance = null, ?Money $discount = null): PriceBreakdown
{
    $request = new PricingRequest(
        lines: $lines,
        restaurant: $profile ?? pe126Profile(),
        serviceFee: $serviceFee ?? pe126ServiceFee(),
        distanceKilometres: $distance,
        discountAmount: $discount,
    );

    return (new PricingEngineImplementation)->quote($request);
}

it('sums quantity times base price plus modifier deltas exactly', function (): void {
    $lines = [
        new PricingLine(2, pe126Money(1000), [pe126Money(150), pe126Money(-50)]),
        new PricingLine(1, pe126Money(800)),
    ];

    expect(pe126Quote($lines)->lineSubtotal->minorUnits)->toBe(3000);
});

it('returns a zero Line_Subtotal and Order_Total for an empty Cart', function (): void {
    $breakdown = pe126Quote([], distance: '1.000');

    expect($breakdown->lineSubtotal->minorUnits)->toBe(0)
        ->and($breakdown->orderTotal->minorUnits)->toBe(0)
        ->and($breakdown->lineSubtotal->currency)->toBe(PE126_CURRENCY);
});

it('computes delivery fee as base plus per-kilometre rate times distance', function (): void {
    $breakdown = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(base: 200, perKm: 100), distance: '2.500');

    expect($breakdown->deliveryFee->minorUnits)->toBe(450);
});

it('rounds the per-kilometre delivery term half up', function (): void {
    $breakdown = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(perKm: 100), distance: '0.005');

    expect($breakdown->deliveryFee->minorUnits)->toBe(1);
});

it('caps the delivery distance at 20 kilometres', function (): void {
    $capped = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(perKm: 100), distance: '50.000');
    $exactly20 = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(perKm: 100), distance: '20.000');

    expect($capped->deliveryFee->minorUnits)->toBe(2000)
        ->and($exactly20->deliveryFee->minorUnits)->toBe(2000);
});

it('clamps the delivery fee to the configured minimum and maximum', function (): void {
    $belowMin = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(base: 50, min: 300, max: 900), distance: '0.000');
    $aboveMax = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(base: 100, perKm: 100, min: 300, max: 900), distance: '20.000');

    expect($belowMin->deliveryFee->minorUnits)->toBe(300)
        ->and($aboveMax->deliveryFee->minorUnits)->toBe(900);
});

it('uses the minimum delivery fee and marks the quote provisional when no address is given', function (): void {
    $breakdown = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(base: 200, perKm: 100, min: 150, max: 900), distance: null);

    expect($breakdown->provisional)->toBeTrue()
        ->and($breakdown->deliveryFee->minorUnits)->toBe(150);
});

it('marks a quote with an address non-provisional', function (): void {
    expect(pe126Quote([new PricingLine(1, pe126Money(1000))], distance: '1.000')->provisional)->toBeFalse();
});

it('computes the service fee as a half-up percentage of the line subtotal', function (): void {
    expect(pe126Quote([new PricingLine(1, pe126Money(1000))], serviceFee: pe126ServiceFee(bp: 1000))->serviceFee->minorUnits)->toBe(100);
});

it('rounds the service fee half up', function (): void {
    expect(pe126Quote([new PricingLine(1, pe126Money(15))], serviceFee: pe126ServiceFee(bp: 1000))->serviceFee->minorUnits)->toBe(2);
});

it('clamps the service fee to its absolute minimum and maximum', function (): void {
    $belowMin = pe126Quote([new PricingLine(1, pe126Money(1000))], serviceFee: pe126ServiceFee(bp: 100, min: 50, max: 200));
    $aboveMax = pe126Quote([new PricingLine(1, pe126Money(1000))], serviceFee: pe126ServiceFee(bp: 2500, min: 50, max: 200));

    expect($belowMin->serviceFee->minorUnits)->toBe(50)
        ->and($aboveMax->serviceFee->minorUnits)->toBe(200);
});

it('computes tax half up on the subtotal plus delivery plus service, before discount', function (): void {
    $breakdown = pe126Quote(
        [new PricingLine(1, pe126Money(1000))],
        profile: pe126Profile(base: 200, taxBp: 800),
        serviceFee: pe126ServiceFee(bp: 1000),
        distance: '0.000',
        discount: pe126Money(500),
    );

    expect($breakdown->taxAmount->minorUnits)->toBe(104);
});

it('clamps the discount to the line subtotal plus delivery fee', function (): void {
    $breakdown = pe126Quote([new PricingLine(1, pe126Money(1000))], profile: pe126Profile(base: 200), distance: '0.000', discount: pe126Money(5000));

    expect($breakdown->discountAmount->minorUnits)->toBe(1200);
});

it('treats a missing promotion as a zero discount', function (): void {
    expect(pe126Quote([new PricingLine(1, pe126Money(1000))])->discountAmount->minorUnits)->toBe(0);
});

it('composes the order total from every component minus the discount', function (): void {
    $breakdown = pe126Quote(
        [new PricingLine(1, pe126Money(1000))],
        profile: pe126Profile(base: 200, taxBp: 800),
        serviceFee: pe126ServiceFee(bp: 1000),
        distance: '0.000',
        discount: pe126Money(300),
    );

    expect($breakdown->orderTotal->minorUnits)->toBe(1104)
        ->and($breakdown->lineSubtotal->minorUnits)->toBe(1000)
        ->and($breakdown->deliveryFee->minorUnits)->toBe(200)
        ->and($breakdown->serviceFee->minorUnits)->toBe(100)
        ->and($breakdown->taxAmount->minorUnits)->toBe(104)
        ->and($breakdown->discountAmount->minorUnits)->toBe(300)
        ->and($breakdown->orderTotal->currency)->toBe(PE126_CURRENCY);
});

it('is deterministic: identical inputs yield identical breakdowns', function (): void {
    $make = fn () => pe126Quote(
        [new PricingLine(3, pe126Money(1250), [pe126Money(200)])],
        profile: pe126Profile(base: 199, perKm: 120, min: 199, max: 1500, taxBp: 750),
        serviceFee: pe126ServiceFee(bp: 500, min: 25, max: 400),
        distance: '3.750',
        discount: pe126Money(600),
    );

    expect($make()->toArray())->toEqual($make()->toArray());
});
