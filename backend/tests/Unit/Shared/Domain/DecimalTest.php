<?php

declare(strict_types=1);

/*
 * Decimal: the single half-up rounding step the shared kernel takes on
 * fixed-precision decimal strings (Requirements 7.2, 7.4, 40.7).
 */

use App\Shared\Domain\Decimal;

it('recognises a plain decimal literal', function (string $value, bool $expected): void {
    expect(Decimal::isDecimal($value))->toBe($expected);
})->with([
    'integer' => ['24', true],
    'signed integer' => ['-24', true],
    'plus sign' => ['+24', true],
    'fractional' => ['0.5', true],
    'many digits' => ['-180.0000001', true],
    'blank' => ['', false],
    'exponent notation' => ['1e2', false],
    'trailing separator' => ['24.', false],
    'leading separator' => ['.5', false],
    'letters' => ['abc', false],
    'spaces' => [' 24 ', false],
]);

it('rounds half up away from zero at the .5 boundary', function (string $value, int $scale, string $expected): void {
    expect(Decimal::roundHalfUp($value, $scale))->toBe($expected);
})->with([
    'exactly half rounds up' => ['0.5', 0, '1'],
    'just below half rounds down' => ['0.4999999', 0, '0'],
    'just above half rounds up' => ['0.5000001', 0, '1'],
    'negative half rounds away from zero' => ['-0.5', 0, '-1'],
    'negative below half rounds toward zero' => ['-0.4', 0, '0'],
    'half at the third digit' => ['1.2345', 3, '1.235'],
    'below half at the third digit' => ['1.2344', 3, '1.234'],
    'half at the seventh digit' => ['24.71360005', 7, '24.7136001'],
    'carry propagates across the separator' => ['9.99', 1, '10.0'],
    'carry adds a digit' => ['9.95', 1, '10.0'],
    'carry grows the integer part' => ['99.999', 2, '100.00'],
]);

it('pads a short fractional part up to the scale', function (): void {
    expect(Decimal::roundHalfUp('1', 3))->toBe('1.000')
        ->and(Decimal::roundHalfUp('1.5', 3))->toBe('1.500')
        ->and(Decimal::roundHalfUp('1.500', 3))->toBe('1.500');
});

it('returns a canonical string for equal values', function (): void {
    expect(Decimal::roundHalfUp('+007.50', 2))->toBe('7.50')
        ->and(Decimal::roundHalfUp('7.5', 2))->toBe('7.50')
        ->and(Decimal::roundHalfUp('-0.00', 2))->toBe('0.00')
        ->and(Decimal::roundHalfUp('-0.004', 2))->toBe('0.00')
        ->and(Decimal::roundHalfUp('0', 0))->toBe('0');
});

it('rejects a negative scale', function (): void {
    expect(static fn (): string => Decimal::roundHalfUp('1.5', -1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a value that is not a decimal', function (): void {
    expect(static fn (): string => Decimal::roundHalfUp('1e2', 2))
        ->toThrow(InvalidArgumentException::class);
});

it('scales a decimal to an integer for exact comparison', function (string $value, int $scale, int $expected): void {
    expect(Decimal::toScaledInt($value, $scale))->toBe($expected);
})->with([
    'seven digits' => ['1.2345678', 7, 12345678],
    'negative' => ['-90', 7, -900000000],
    'zero' => ['-0.0000000', 7, 0],
    'rounds before scaling' => ['1.23456785', 7, 12345679],
    'scale zero' => ['1.5', 0, 2],
]);

it('refuses a value carrying more significant digits than an integer holds', function (): void {
    expect(static fn (): int => Decimal::toScaledInt('1234567890123456789', 0))
        ->toThrow(InvalidArgumentException::class);
});

it('compares two decimals at a scale', function (string $left, string $right, int $scale, int $expected): void {
    expect(Decimal::compare($left, $right, $scale))->toBe($expected);
})->with([
    'less than' => ['1.0000000', '1.0000001', 7, -1],
    'equal' => ['1.0', '1.0000000', 7, 0],
    'greater than' => ['1.0000001', '1.0000000', 7, 1],
    'equal after rounding at the scale' => ['1.00000004', '1.0000000', 7, 0],
    'differs only beyond the scale' => ['1.0000001', '1.0000000', 3, 0],
    'range bound' => ['90.0000001', '90', 7, 1],
    'negative range bound' => ['-90.0000001', '-90', 7, -1],
]);
