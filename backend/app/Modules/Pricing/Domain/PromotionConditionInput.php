<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * One eligibility condition carried on a {@see CreatePromotionCommand}, before
 * it is turned into a persisted {@see EligibilityCondition} (Requirement 7.6).
 *
 * The Form_Request shapes the raw request into these, the service validates each
 * against its kind, and the repository stores the kind and operand. Keeping the
 * input a bare kind-and-operand pair rather than a concrete condition keeps the
 * creation surface free of the condition class hierarchy.
 */
final readonly class PromotionConditionInput
{
    public function __construct(
        public ConditionKind $kind,
        public int|string|null $operand,
    ) {}
}
