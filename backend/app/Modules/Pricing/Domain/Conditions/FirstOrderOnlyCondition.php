<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Conditions;

use App\Modules\Pricing\Domain\ConditionKind;
use App\Modules\Pricing\Domain\EligibilityCondition;
use App\Modules\Pricing\Domain\PromotionContext;

/**
 * Satisfied only when the order is the Customer's first (Requirement 7.6).
 *
 * The condition carries no operand; it reads
 * {@see PromotionContext::$isFirstOrder}, which the caller sets from the owning
 * Customer's order history. A Customer who has placed an earlier Order fails
 * this condition and receives no discount.
 */
final readonly class FirstOrderOnlyCondition implements EligibilityCondition
{
    public function kind(): ConditionKind
    {
        return ConditionKind::FirstOrderOnly;
    }

    public function operand(): null
    {
        return null;
    }

    public function isSatisfiedBy(PromotionContext $context): bool
    {
        return $context->isFirstOrder;
    }
}
