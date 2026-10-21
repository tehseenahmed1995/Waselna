<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts\Services;

use App\Modules\Pricing\Domain\CreatePromotionCommand;
use App\Modules\Pricing\Domain\PromotionContext;
use App\Modules\Pricing\Domain\PromotionEvaluation;
use App\Modules\Pricing\Domain\PromotionView;

/**
 * The Promotion Service_Interface (Requirements 7.5–7.7, 18.4; design
 * "Pricing module").
 *
 * The interface is a Module_Boundary seam: the Admin surface depends on
 * {@see create()} to register a Promotion, and the checkout/pricing path depends
 * on {@see evaluate()} to resolve a Discount_Amount for a Cart, neither coupling
 * to the implementation or to the `promotions` schema.
 *
 * {@see evaluate()} takes a {@see PromotionContext} rather than the design's
 * `CartSnapshot`: the Cart module is not yet built, and Pricing owns neither the
 * Cart nor the Order, so the caller assembles the framework-free context from
 * its own data. The Promotion window is evaluated against the injected Clock in
 * the implementation, never wall-clock time, so eligibility is deterministic
 * under test.
 */
interface PromotionService
{
    /**
     * Creates a Promotion from an Administrator command, validating the
     * discount value range, the activation window, and every condition before
     * persisting (Requirement 18.4). Rejects an out-of-range submission with
     * `validation_failed` and creates nothing.
     */
    public function create(CreatePromotionCommand $command): PromotionView;

    /**
     * Resolves the Discount_Amount for a single Promotion code applied to the
     * Cart context, clamped to `[0, Line_Subtotal + Delivery_Fee]`
     * (Requirements 7.5, 7.7).
     *
     * Rejects an unknown code, a code outside its activation window at the
     * current instant, an ineligible code, or a usage-exhausted code with
     * `promotion_not_applicable` and leaves the Cart unchanged (Requirement 7.6).
     */
    public function evaluate(string $code, PromotionContext $context): PromotionEvaluation;
}
