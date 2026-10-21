<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Conditions;

use App\Modules\Pricing\Domain\ConditionKind;
use App\Modules\Pricing\Domain\EligibilityCondition;
use App\Modules\Pricing\Domain\PromotionContext;
use InvalidArgumentException;

/**
 * Satisfied when the Cart's Line_Subtotal reaches a minimum threshold
 * (Requirement 7.6).
 *
 * The threshold is a non-negative integer number of minor currency units,
 * compared against {@see PromotionContext::$lineSubtotal}. The comparison is
 * inclusive: a Line_Subtotal exactly equal to the threshold satisfies the
 * condition.
 */
final readonly class MinimumSubtotalCondition implements EligibilityCondition
{
    public function __construct(public int $minimumMinorUnits)
    {
        if ($minimumMinorUnits < 0) {
            throw new InvalidArgumentException(sprintf(
                'A minimum-subtotal threshold cannot be negative; received %d.',
                $minimumMinorUnits,
            ));
        }
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::MinimumSubtotal;
    }

    public function operand(): int
    {
        return $this->minimumMinorUnits;
    }

    public function isSatisfiedBy(PromotionContext $context): bool
    {
        return $context->lineSubtotal->minorUnits >= $this->minimumMinorUnits;
    }
}
