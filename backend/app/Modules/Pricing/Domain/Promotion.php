<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A Promotion as the Pricing_Engine evaluates it: a named discount rule with an
 * activation window, eligibility conditions, a usage cap, and a discount value
 * (Requirements 7.5–7.7, 18.4; design "Pricing module").
 *
 * This is a framework-free Domain aggregate, not an Eloquent model: the
 * repository rehydrates one from the `promotions`/`promotion_conditions` rows,
 * and all eligibility and discount logic lives here so the service and the
 * engine share one authority. Every time-dependent decision takes the current
 * instant as a parameter — the service reads it from the injected Clock and
 * passes it in — so this class never touches wall-clock time and a given
 * instant always yields the same decision (design "Clock and IdGenerator").
 *
 * Eligibility is decided by {@see isApplicableAt()}: the Promotion must be
 * active, inside its window at the supplied instant, under its usage cap, scoped
 * to the Cart's Restaurant where it carries a scope, and satisfy every
 * {@see EligibilityCondition}. The discount a Promotion yields is computed by
 * {@see discountFor()} as a minor-unit {@see Money}; the engine then clamps it
 * to the Requirement 7.7 range, so this computation does not itself clamp to the
 * Cart total.
 */
final readonly class Promotion
{
    /**
     * @param  list<EligibilityCondition>  $conditions  every one must be satisfied (Requirement 7.6)
     */
    public function __construct(
        public string $id,
        public string $code,
        public DiscountKind $discountKind,
        public int $discountValue,
        public DateTimeImmutable $activatesAt,
        public DateTimeImmutable $expiresAt,
        public bool $isActive,
        public ?int $maxUses,
        public int $timesUsed,
        public ?string $restaurantId = null,
        public array $conditions = [],
    ) {
        if (! $discountKind->permits($discountValue)) {
            throw new InvalidArgumentException(sprintf(
                'A %s discount value must fall in the closed range %d to %d; received %d (Requirement 18.4).',
                $discountKind->value,
                $discountKind->minimumValue(),
                $discountKind->maximumValue(),
                $discountValue,
            ));
        }

        if ($activatesAt >= $expiresAt) {
            throw new InvalidArgumentException(
                'A Promotion activation timestamp must be earlier than its expiry timestamp (Requirement 18.4).'
            );
        }

        if ($maxUses !== null && $maxUses < 1) {
            throw new InvalidArgumentException(sprintf(
                'A Promotion usage cap, when set, must be at least 1; received %d.',
                $maxUses,
            ));
        }

        if ($timesUsed < 0) {
            throw new InvalidArgumentException(sprintf(
                'A Promotion usage count cannot be negative; received %d.',
                $timesUsed,
            ));
        }
    }

    /**
     * Whether the supplied instant falls inside the activation window
     * (Requirement 18.4): activation inclusive, expiry exclusive. The instant is
     * supplied by the caller from the injected Clock, never read here.
     */
    public function isWithinWindow(DateTimeImmutable $now): bool
    {
        return $now >= $this->activatesAt && $now < $this->expiresAt;
    }

    /**
     * Whether the Promotion still has redemptions left under its cap. An unset
     * cap (`maxUses` null) is unlimited.
     */
    public function hasUsesRemaining(): bool
    {
        return $this->maxUses === null || $this->timesUsed < $this->maxUses;
    }

    /**
     * Whether this Promotion is scoped to a Restaurant other than the Cart's.
     */
    private function isScopedAway(PromotionContext $context): bool
    {
        return $this->restaurantId !== null && $this->restaurantId !== $context->restaurantId;
    }

    /**
     * Whether every eligibility condition is satisfied by the Cart context
     * (Requirement 7.6).
     */
    public function conditionsSatisfiedBy(PromotionContext $context): bool
    {
        foreach ($this->conditions as $condition) {
            if (! $condition->isSatisfiedBy($context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this Promotion applies to the Cart at the supplied instant: it is
     * active, inside its window, under its usage cap, scoped to the Cart's
     * Restaurant, and every eligibility condition holds (Requirements 7.5, 7.6).
     */
    public function isApplicableAt(DateTimeImmutable $now, PromotionContext $context): bool
    {
        return $this->isActive
            && $this->isWithinWindow($now)
            && $this->hasUsesRemaining()
            && ! $this->isScopedAway($context)
            && $this->conditionsSatisfiedBy($context);
    }

    /**
     * The unclamped Discount_Amount this Promotion yields for the Cart context,
     * as a minor-unit {@see Money} in the Cart currency (Requirement 7.5).
     *
     * An `amount` Promotion yields its fixed value; a `percent` Promotion yields
     * that whole-number percentage of `Line_Subtotal + Delivery_Fee`, taken with
     * a single half-up rounding step (Requirement 7.4). The caller — the
     * Pricing_Engine — then clamps the result to `[0, Line_Subtotal +
     * Delivery_Fee]` (Requirement 7.7); this method does not clamp.
     */
    public function discountFor(PromotionContext $context): Money
    {
        return match ($this->discountKind) {
            DiscountKind::Amount => Money::ofMinorUnits($this->discountValue, $context->currency),
            DiscountKind::Percent => $context->discountableBase()
                ->percentHalfUp($this->discountValue * 100),
        };
    }
}
