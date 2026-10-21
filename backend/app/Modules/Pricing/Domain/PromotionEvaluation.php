<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;

/**
 * The result of evaluating one Promotion code against a Cart
 * (Requirements 7.5–7.7).
 *
 * A successful evaluation carries the applied Promotion's identifier and the
 * Discount_Amount already clamped to `[0, Line_Subtotal + Delivery_Fee]`
 * (Requirement 7.7), so the caller can both deduct the discount and record the
 * redemption against the right Promotion. An unknown code, a code outside its
 * activation window, an ineligible code, or more than one code does not yield an
 * evaluation at all: the service rejects it with `promotion_not_applicable` and
 * a Discount_Amount of 0, leaving the Cart unchanged (Requirement 7.6).
 */
final readonly class PromotionEvaluation
{
    public function __construct(
        public string $promotionId,
        public string $code,
        public Money $discountAmount,
    ) {}
}
