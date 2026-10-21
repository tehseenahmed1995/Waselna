<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * One deterministic eligibility condition a Promotion imposes on a Cart
 * (Requirement 7.6).
 *
 * A Promotion applies only when every one of its conditions is satisfied by the
 * {@see PromotionContext}; a single unsatisfied condition makes the Promotion
 * ineligible and yields a Discount_Amount of 0 (Requirement 7.6). Each
 * implementation evaluates purely against the supplied context — it reads no
 * clock, no database, and no configuration — so a given context always produces
 * the same decision.
 */
interface EligibilityCondition
{
    /**
     * The condition kind, used to persist and rehydrate the condition.
     */
    public function kind(): ConditionKind;

    /**
     * The condition operand as it is stored in `promotion_conditions.operand`,
     * or null for a flag condition that carries none.
     */
    public function operand(): int|string|null;

    /**
     * Whether the supplied Cart context satisfies this condition.
     */
    public function isSatisfiedBy(PromotionContext $context): bool;
}
