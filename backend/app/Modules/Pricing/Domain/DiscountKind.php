<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * How a Promotion's {@see Promotion::$discountValue} is interpreted
 * (design `discount_kind`; Requirement 18.4).
 *
 * An `amount` Promotion carries a fixed discount in integer minor currency
 * units, in the closed range 1 to 99,999,999. A `percent` Promotion carries a
 * whole-number percentage, in the closed range 1 to 100, applied to the base
 * the Pricing_Engine clamps against. Both are evaluated into a minor-unit
 * Discount_Amount by {@see Promotion::discountFor()} before the engine clamps it
 * to the Requirement 7.7 range.
 */
enum DiscountKind: string
{
    case Amount = 'amount';
    case Percent = 'percent';

    /**
     * The inclusive lower bound a value of this kind must meet (Requirement 18.4).
     */
    public function minimumValue(): int
    {
        return 1;
    }

    /**
     * The inclusive upper bound a value of this kind must meet (Requirement 18.4):
     * 99,999,999 minor units for an amount, 100 percent for a percentage.
     */
    public function maximumValue(): int
    {
        return match ($this) {
            self::Amount => 99999999,
            self::Percent => 100,
        };
    }

    /**
     * Whether $value lies in the inclusive range this kind permits.
     */
    public function permits(int $value): bool
    {
        return $value >= $this->minimumValue() && $value <= $this->maximumValue();
    }
}
