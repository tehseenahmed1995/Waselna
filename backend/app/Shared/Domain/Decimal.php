<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale decimal arithmetic over plain strings.
 *
 * Requirement 40.7 stores coordinates as fixed-precision decimals, and
 * Requirements 7.2 and 7.4 demand exactly one explicit half-up rounding step
 * per produced value. Binary floating point can promise neither, so every
 * decimal the shared kernel carries is a string and every rounding decision is
 * taken here through integer digit arithmetic — no extension, no framework.
 *
 * Rounding is half up away from zero, matching PHP's PHP_ROUND_HALF_UP.
 *
 * @internal Supporting arithmetic for the shared kernel value objects.
 */
final class Decimal
{
    /**
     * A decimal literal: optional sign, at least one integer digit, optional
     * fractional part. Exponent notation is rejected on purpose — a stored
     * coordinate is never written that way.
     */
    private const PATTERN = '/^[+-]?\d+(?:\.\d+)?$/';

    /**
     * Widest digit string convertible to an int without overflow risk.
     */
    private const MAX_SIGNIFICANT_DIGITS = 18;

    private function __construct()
    {
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Rounds half up, away from zero, to exactly $scale fractional digits.
     *
     * The result is canonical: no sign on zero, no redundant leading zero, and
     * always exactly $scale fractional digits, so equal values are equal
     * strings and a stored value round-trips unchanged.
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException(sprintf('A decimal scale cannot be negative; received %d.', $scale));
        }

        if (! self::isDecimal($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal number.', $value));
        }

        $negative = $value[0] === '-';
        $magnitude = ltrim($value, '+-');

        $separator = strpos($magnitude, '.');
        $integerDigits = $separator === false ? $magnitude : substr($magnitude, 0, $separator);
        $fractionDigits = $separator === false ? '' : substr($magnitude, $separator + 1);

        if (strlen($fractionDigits) <= $scale) {
            $fractionDigits = str_pad($fractionDigits, $scale, '0');
        } else {
            $roundsUp = $fractionDigits[$scale] >= '5';
            $fractionDigits = substr($fractionDigits, 0, $scale);

            if ($roundsUp) {
                [$integerDigits, $fractionDigits] = self::incrementAtScale($integerDigits, $fractionDigits, $scale);
            }
        }

        $integerDigits = self::stripLeadingZeros($integerDigits);
        $formatted = $scale === 0 ? $integerDigits : $integerDigits.'.'.$fractionDigits;

        if (! $negative || self::isZeroDigits($integerDigits.$fractionDigits)) {
            return $formatted;
        }

        return '-'.$formatted;
    }

    /**
     * The value multiplied by 10^$scale, rounded half up, as an integer.
     *
     * Comparing two scaled integers is an exact comparison of two decimals at
     * that scale, which is how coordinate range checks stay free of floats.
     */
    public static function toScaledInt(string $value, int $scale): int
    {
        $rounded = self::roundHalfUp($value, $scale);
        $negative = $rounded[0] === '-';
        $digits = self::stripLeadingZeros(str_replace(['-', '.'], '', $rounded));

        if (strlen($digits) > self::MAX_SIGNIFICANT_DIGITS) {
            throw new InvalidArgumentException(
                sprintf('"%s" carries more significant digits than an integer can hold.', $value)
            );
        }

        $scaled = (int) $digits;

        return $negative ? -$scaled : $scaled;
    }

    /**
     * Compares two decimals at $scale: -1, 0, or 1.
     */
    public static function compare(string $left, string $right, int $scale): int
    {
        return self::toScaledInt($left, $scale) <=> self::toScaledInt($right, $scale);
    }

    /**
     * Adds one unit at $scale to the magnitude held as integer and fraction
     * digit strings, propagating the carry across the decimal separator.
     *
     * @return array{0: string, 1: string}
     */
    private static function incrementAtScale(string $integerDigits, string $fractionDigits, int $scale): array
    {
        $incremented = self::incrementDigits($integerDigits.$fractionDigits);

        if ($scale === 0) {
            return [$incremented, ''];
        }

        $carriedInteger = substr($incremented, 0, strlen($incremented) - $scale);

        return [
            $carriedInteger === '' ? '0' : $carriedInteger,
            substr($incremented, -$scale),
        ];
    }

    private static function incrementDigits(string $digits): string
    {
        for ($position = strlen($digits) - 1; $position >= 0; $position--) {
            if ($digits[$position] !== '9') {
                $digits[$position] = (string) ((int) $digits[$position] + 1);

                return $digits;
            }

            $digits[$position] = '0';
        }

        return '1'.$digits;
    }

    private static function stripLeadingZeros(string $digits): string
    {
        $stripped = ltrim($digits, '0');

        return $stripped === '' ? '0' : $stripped;
    }

    private static function isZeroDigits(string $digits): bool
    {
        return ltrim($digits, '0') === '';
    }
}
