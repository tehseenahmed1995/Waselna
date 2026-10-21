<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * One priced Cart_Line (or Order_Line) fed into the Pricing_Engine.
 *
 * A line carries its quantity in the closed range 1 to 20 (Requirement 40.4),
 * its base Price (Requirement 34.2), and the Price_Deltas of the selected
 * Modifier_Options (Requirement 7.1). The line subtotal is
 * `quantity × (basePrice + Σ modifierDeltas)`, computed exactly in integer
 * minor units: the quantity multiplication and the delta additions are exact,
 * so no rounding step is produced here (Requirement 7.4).
 *
 * Only available lines reach the engine; a Cart_Line marked unavailable is
 * excluded by the caller before a {@see PricingRequest} is built (Requirement
 * 7.1), so the engine never has to re-check availability.
 */
final readonly class PricingLine
{
    /**
     * @param  list<Money>  $modifierDeltas  the Price_Deltas of the selected Modifier_Options
     */
    public function __construct(
        public int $quantity,
        public Money $basePrice,
        public array $modifierDeltas = [],
    ) {
        if ($quantity < 1 || $quantity > 20) {
            throw new InvalidArgumentException(sprintf(
                'A Cart_Line quantity must fall in the closed range 1 to 20; received %d.',
                $quantity,
            ));
        }

        foreach ($modifierDeltas as $delta) {
            if ($delta->currency !== $basePrice->currency) {
                throw new InvalidArgumentException(sprintf(
                    'A modifier delta in %s cannot price a line in %s.',
                    $delta->currency,
                    $basePrice->currency,
                ));
            }
        }
    }

    /**
     * The currency this line is priced in, taken from its base Price.
     */
    public function currency(): string
    {
        return $this->basePrice->currency;
    }

    /**
     * The exact subtotal of this line: `quantity × (basePrice + Σ deltas)`.
     *
     * The computation is integer-exact — a sum of signed minor-unit amounts
     * multiplied by a whole quantity — so it produces no value that needs
     * rounding (Requirement 7.4). A line whose modifier deltas drive the unit
     * price below zero is a caller error that surfaces here rather than being
     * silently clamped; the Pricing_Engine asserts the summed Line_Subtotal is
     * non-negative.
     */
    public function subtotal(): Money
    {
        $unitPrice = $this->basePrice;

        foreach ($this->modifierDeltas as $delta) {
            $unitPrice = $unitPrice->plus($delta);
        }

        return $unitPrice->timesQuantity($this->quantity);
    }
}
