<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * A monetary amount as an integer number of minor currency units.
 *
 * Requirement 7.4 requires every monetary calculation in integer minor units
 * with a single half-up rounding step at the step that produces a value, so
 * that identical inputs always yield identical outputs. Requirement 20.9
 * returns every amount as that integer plus an ISO 4217 code, and Requirement
 * 40.4 restricts every *stored* amount to the closed range 0 to 99,999,999.
 *
 * The stored range is not enforced at construction: signed Price_Deltas and
 * intermediate subtraction results legitimately fall outside it. Callers that
 * are about to persist or publish an amount assert the range explicitly with
 * {@see Money::assertWithinStorableRange()}.
 *
 * Rounding is half up away from zero, matching PHP_ROUND_HALF_UP.
 */
final readonly class Money
{
    public const MIN_STORABLE_MINOR_UNITS = 0;

    public const MAX_STORABLE_MINOR_UNITS = 99999999;

    /**
     * 1,000,000 basis points is 10,000 percent — far above the 25 percent
     * Service_Fee ceiling of Requirement 7.9 and the 30 percent tax ceiling of
     * Requirement 7.10, while still keeping the product of an amount and a rate
     * comfortably inside an integer.
     */
    public const MAX_BASIS_POINTS = 1000000;

    private const BASIS_POINTS_PER_UNIT = 10000;

    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not an ISO 4217 currency code.', $currency)
            );
        }
    }

    public static function ofMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiplies by a Cart_Line or Order_Line quantity. Exact: no rounding step
     * is produced, so none is taken.
     */
    public function timesQuantity(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException(
                sprintf('A quantity cannot be negative; received %d.', $quantity)
            );
        }

        return new self(self::multiplyExactly($this->minorUnits, $quantity), $this->currency);
    }

    /**
     * Applies a percentage carried as integer basis points (1 percent = 100
     * basis points) with exactly one half-up rounding step to the nearest minor
     * unit (Requirement 7.4).
     */
    public function percentHalfUp(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new InvalidArgumentException(sprintf(
                'A basis-point rate must fall in the closed range 0 to %d; received %d.',
                self::MAX_BASIS_POINTS,
                $basisPoints,
            ));
        }

        $product = self::multiplyExactly(abs($this->minorUnits), $basisPoints);
        $whole = intdiv($product, self::BASIS_POINTS_PER_UNIT);

        if (($product % self::BASIS_POINTS_PER_UNIT) * 2 >= self::BASIS_POINTS_PER_UNIT) {
            $whole++;
        }

        return new self($this->minorUnits < 0 ? -$whole : $whole, $this->currency);
    }

    /**
     * Restricts the amount to the closed range [$minimum, $maximum], as
     * Requirements 7.2, 7.7, and 7.9 require of Delivery_Fee, Discount_Amount,
     * and Service_Fee.
     */
    public function clamp(self $minimum, self $maximum): self
    {
        $this->assertSameCurrency($minimum);
        $this->assertSameCurrency($maximum);

        if ($minimum->minorUnits > $maximum->minorUnits) {
            throw new InvalidArgumentException(sprintf(
                'A clamp minimum cannot exceed its maximum; received %d and %d.',
                $minimum->minorUnits,
                $maximum->minorUnits,
            ));
        }

        if ($this->minorUnits < $minimum->minorUnits) {
            return $minimum;
        }

        if ($this->minorUnits > $maximum->minorUnits) {
            return $maximum;
        }

        return $this;
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    /**
     * Orders two amounts of one currency: -1, 0, or 1.
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function isWithinStorableRange(): bool
    {
        return $this->minorUnits >= self::MIN_STORABLE_MINOR_UNITS
            && $this->minorUnits <= self::MAX_STORABLE_MINOR_UNITS;
    }

    /**
     * Guards the Requirement 40.4 range before an amount is persisted or
     * returned, so an out-of-range total fails in the domain rather than at the
     * database check constraint.
     */
    public function assertWithinStorableRange(): void
    {
        if ($this->isWithinStorableRange()) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'A stored monetary amount must fall in the closed range %d to %d minor units; received %d.',
            self::MIN_STORABLE_MINOR_UNITS,
            self::MAX_STORABLE_MINOR_UNITS,
            $this->minorUnits,
        ));
    }

    /**
     * The Requirement 20.9 representation: an integer amount with its currency.
     *
     * @return array{amount: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left !== 0 && $right !== 0 && abs($left) > intdiv(PHP_INT_MAX, abs($right))) {
            throw new InvalidArgumentException(
                sprintf('Multiplying %d by %d overflows an integer.', $left, $right)
            );
        }

        return $left * $right;
    }
}
