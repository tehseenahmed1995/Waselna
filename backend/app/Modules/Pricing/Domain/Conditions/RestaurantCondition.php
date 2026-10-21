<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Conditions;

use App\Modules\Pricing\Domain\ConditionKind;
use App\Modules\Pricing\Domain\EligibilityCondition;
use App\Modules\Pricing\Domain\PromotionContext;
use InvalidArgumentException;

/**
 * Satisfied when the Cart's Restaurant equals a specific Restaurant
 * (Requirement 7.6).
 *
 * The operand is the required Restaurant identifier, compared for exact string
 * equality against {@see PromotionContext::$restaurantId}. A Promotion scoped to
 * one Restaurant through its `restaurant_id` column enforces the same rule
 * directly; this condition exists so a Promotion assembled from a generic
 * condition list can carry the scope without the dedicated column.
 */
final readonly class RestaurantCondition implements EligibilityCondition
{
    public function __construct(public string $restaurantId)
    {
        if ($restaurantId === '') {
            throw new InvalidArgumentException(
                'A Restaurant condition requires a non-empty Restaurant identifier.'
            );
        }
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Restaurant;
    }

    public function operand(): string
    {
        return $this->restaurantId;
    }

    public function isSatisfiedBy(PromotionContext $context): bool
    {
        return $context->restaurantId === $this->restaurantId;
    }
}
