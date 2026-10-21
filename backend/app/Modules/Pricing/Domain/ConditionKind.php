<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

/**
 * The eligibility-condition kinds a Promotion may carry (design
 * `promotion_conditions.kind`; Requirement 7.6).
 *
 * Each kind maps to one {@see EligibilityCondition} that evaluates
 * deterministically against a {@see PromotionContext}. The activation window and
 * the `is_active` flag are carried on the Promotion itself rather than as
 * conditions, because Requirement 7.6 treats "outside its activation window" as
 * a distinct rejection cause from an unmet eligibility condition.
 */
enum ConditionKind: string
{
    /**
     * The Cart's Line_Subtotal must be at least the operand (a minor-unit
     * threshold).
     */
    case MinimumSubtotal = 'minimum_subtotal';

    /**
     * The Cart's Restaurant must equal the operand (a Restaurant identifier).
     * This duplicates the Promotion's own `restaurant_id` scope so a Promotion
     * can scope to a Restaurant without an owning column, kept as a condition
     * for Promotions created through the generic condition list.
     */
    case Restaurant = 'restaurant';

    /**
     * The order must be the Customer's first (the operand is empty).
     */
    case FirstOrderOnly = 'first_order_only';
}
