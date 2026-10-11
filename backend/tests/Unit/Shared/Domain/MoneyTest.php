<?php

declare(strict_types=1);

/*
 * Money: integer minor units, one half-up rounding step per produced value
 * (Requirement 7.4), and the stored range 0 to 99,999,999 (Requirement 40.4).
 */

use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;

it('carries the amount as integer minor units with its ISO 4217 currency', function (): void {
    $money = Money::ofMinorUnits(1250, 'SAR');

    expect($money->minorUnits)->toBe(1250)
        ->and($money->currency)->toBe('SAR')
        ->and($money->toArray())->toBe(['amount' => 1250, 'currency' => 'SAR']);
});

it('rejects anything that is not a three-letter uppercase currency code', function (string $currency): void {
    expect(static fn (): Money => Money::ofMinorUnits(100, $currency))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'lowercase' => ['sar'],
    'mixed case' => ['Sar'],
    'two letters' => ['SA'],
    'four letters' => ['SARX'],
    'digits' => ['123'],
    'blank' => [''],
]);

it('reports zero, positive, and negative amounts', function (): void {
    expect(Money::zero('SAR')->isZero())->toBeTrue()
        ->and(Money::zero('SAR')->minorUnits)->toBe(0)
        ->and(Money::ofMinorUnits(1, 'SAR')->isPositive())->toBeTrue()
        ->and(Money::ofMinorUnits(-1, 'SAR')->isNegative())->toBeTrue()
        ->and(Money::ofMinorUnits(-1, 'SAR')->negated()->minorUnits)->toBe(1);
});

it('adds and subtracts in minor units', function (): void {
    $left = Money::ofMinorUnits(99999998, 'SAR');
    $right = Money::ofMinorUnits(1, 'SAR');

    expect($left->plus($right)->minorUnits)->toBe(99999999)
        ->and($left->minus($right)->minorUnits)->toBe(99999997)
        ->and($right->minus($left)->minorUnits)->toBe(-99999997);
});

it('treats equality as amount and currency together', function (): void {
    $amount = Money::ofMinorUnits(500, 'SAR');

    expect($amount->equals(Money::ofMinorUnits(500, 'SAR')))->toBeTrue()
        ->and($amount->equals(Money::ofMinorUnits(501, 'SAR')))->toBeFalse()
        ->and($amount->equals(Money::ofMinorUnits(500, 'USD')))->toBeFalse();
});

it('orders two amounts of one currency', function (): void {
    $amount = Money::ofMinorUnits(500, 'SAR');

    expect($amount->compareTo(Money::ofMinorUnits(501, 'SAR')))->toBe(-1)
        ->and($amount->compareTo(Money::ofMinorUnits(500, 'SAR')))->toBe(0)
        ->and($amount->compareTo(Money::ofMinorUnits(499, 'SAR')))->toBe(1);
});

it('refuses to combine two currencies', function (callable $combine): void {
    expect($combine)->toThrow(CurrencyMismatch::class);
})->with([
    'plus' => [static fn (): Money => Money::ofMinorUnits(100, 'SAR')->plus(Money::ofMinorUnits(100, 'USD'))],
    'minus' => [static fn (): Money => Money::ofMinorUnits(100, 'SAR')->minus(Money::ofMinorUnits(100, 'USD'))],
    'compareTo' => [static fn (): int => Money::ofMinorUnits(100, 'SAR')->compareTo(Money::ofMinorUnits(100, 'USD'))],
    'clamp' => [static fn (): Money => Money::ofMinorUnits(100, 'SAR')->clamp(
        Money::ofMinorUnits(0, 'USD'),
        Money::ofMinorUnits(100, 'USD'),
    )],
]);

it('names both currencies in the mismatch message', function (): void {
    $mismatch = CurrencyMismatch::between('SAR', 'USD');

    expect($mismatch)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($mismatch->getMessage())->toContain('SAR')
        ->and($mismatch->getMessage())->toContain('USD');
});

it('multiplies by a quantity exactly, taking no rounding step', function (): void {
    $unit = Money::ofMinorUnits(1999, 'SAR');

    expect($unit->timesQuantity(0)->minorUnits)->toBe(0)
        ->and($unit->timesQuantity(1)->minorUnits)->toBe(1999)
        ->and($unit->timesQuantity(50)->minorUnits)->toBe(99950);
});

it('rejects a negative quantity', function (): void {
    expect(static fn (): Money => Money::ofMinorUnits(100, 'SAR')->timesQuantity(-1))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a multiplication that overflows an integer', function (): void {
    expect(static fn (): Money => Money::ofMinorUnits(PHP_INT_MAX, 'SAR')->timesQuantity(2))
        ->toThrow(InvalidArgumentException::class);
});

it('rounds a basis-point percentage half up at the .5 boundary', function (int $minorUnits, int $basisPoints, int $expected): void {
    expect(Money::ofMinorUnits($minorUnits, 'SAR')->percentHalfUp($basisPoints)->minorUnits)->toBe($expected);
})->with([
    // 2.5 percent of 100 is exactly 2.5 minor units: half up gives 3.
    'exactly half rounds up' => [100, 250, 3],
    'exactly half rounds up again' => [300, 250, 8],
    'one and a half rounds up' => [100, 150, 2],
    'just below half rounds down' => [100, 249, 2],
    'just above half rounds up' => [100, 251, 3],
    'exact result needs no rounding' => [200, 250, 5],
    'zero rate yields zero' => [99999999, 0, 0],
    'full rate is the identity' => [99999999, 10000, 99999999],
    'the ceiling rate is accepted' => [100, Money::MAX_BASIS_POINTS, 10000],
    'zero amount yields zero' => [0, 1575, 0],
]);

it('rounds a negative amount half up away from zero', function (): void {
    expect(Money::ofMinorUnits(-100, 'SAR')->percentHalfUp(250)->minorUnits)->toBe(-3)
        ->and(Money::ofMinorUnits(-100, 'SAR')->percentHalfUp(249)->minorUnits)->toBe(-2);
});

it('rejects a basis-point rate outside the accepted range', function (int $basisPoints): void {
    expect(static fn (): Money => Money::ofMinorUnits(100, 'SAR')->percentHalfUp($basisPoints))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => [-1],
    'above the ceiling' => [Money::MAX_BASIS_POINTS + 1],
]);

it('produces identical output for identical input', function (): void {
    $first = Money::ofMinorUnits(4999, 'SAR')->percentHalfUp(1575)->plus(Money::ofMinorUnits(750, 'SAR'));
    $second = Money::ofMinorUnits(4999, 'SAR')->percentHalfUp(1575)->plus(Money::ofMinorUnits(750, 'SAR'));

    expect($first->equals($second))->toBeTrue()
        ->and($first->minorUnits)->toBe($second->minorUnits);
});

it('clamps to the closed range', function (int $minorUnits, int $expected): void {
    $clamped = Money::ofMinorUnits($minorUnits, 'SAR')->clamp(
        Money::zero('SAR'),
        Money::ofMinorUnits(1000, 'SAR'),
    );

    expect($clamped->minorUnits)->toBe($expected);
})->with([
    'below the minimum' => [-500, 0],
    'at the minimum' => [0, 0],
    'inside' => [1, 1],
    'at the maximum' => [1000, 1000],
    'above the maximum' => [1001, 1000],
]);

it('rejects a clamp whose minimum exceeds its maximum', function (): void {
    expect(static fn (): Money => Money::ofMinorUnits(100, 'SAR')->clamp(
        Money::ofMinorUnits(200, 'SAR'),
        Money::ofMinorUnits(100, 'SAR'),
    ))->toThrow(InvalidArgumentException::class);
});

it('accepts a storable amount at 0, at 1, and at 99,999,999 minor units', function (int $minorUnits): void {
    $money = Money::ofMinorUnits($minorUnits, 'SAR');

    expect($money->isWithinStorableRange())->toBeTrue();

    $money->assertWithinStorableRange();
})->with([
    'the minimum' => [Money::MIN_STORABLE_MINOR_UNITS],
    'one minor unit' => [1],
    'the maximum' => [Money::MAX_STORABLE_MINOR_UNITS],
]);

it('rejects a stored amount outside the closed range 0 to 99,999,999', function (int $minorUnits): void {
    $money = Money::ofMinorUnits($minorUnits, 'SAR');

    expect($money->isWithinStorableRange())->toBeFalse()
        ->and(static function () use ($money): void {
            $money->assertWithinStorableRange();
        })->toThrow(InvalidArgumentException::class);
})->with([
    'one below the minimum' => [-1],
    'one above the maximum' => [Money::MAX_STORABLE_MINOR_UNITS + 1],
]);

it('keeps the stored-range bounds at the documented values', function (): void {
    expect(Money::MIN_STORABLE_MINOR_UNITS)->toBe(0)
        ->and(Money::MAX_STORABLE_MINOR_UNITS)->toBe(99999999);
});
