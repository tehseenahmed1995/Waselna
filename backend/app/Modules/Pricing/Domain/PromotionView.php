<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * The immutable read projection of a created Promotion, returned to the
 * Administrator surface (Requirement 18.4).
 *
 * Carries the stored shape — identifier, code, discount kind and value, window,
 * active flag, usage cap and count, optional Restaurant scope, and the
 * condition kinds — without exposing the Eloquent model or the condition class
 * hierarchy. Timestamps serialise as RFC 3339 UTC strings.
 */
final readonly class PromotionView
{
    /**
     * @param  list<PromotionConditionInput>  $conditions
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
        public ?string $restaurantId,
        public array $conditions,
    ) {}

    public static function fromPromotion(Promotion $promotion): self
    {
        return new self(
            id: $promotion->id,
            code: $promotion->code,
            discountKind: $promotion->discountKind,
            discountValue: $promotion->discountValue,
            activatesAt: $promotion->activatesAt,
            expiresAt: $promotion->expiresAt,
            isActive: $promotion->isActive,
            maxUses: $promotion->maxUses,
            timesUsed: $promotion->timesUsed,
            restaurantId: $promotion->restaurantId,
            conditions: array_map(
                static fn (EligibilityCondition $c): PromotionConditionInput => new PromotionConditionInput(
                    $c->kind(),
                    $c->operand(),
                ),
                $promotion->conditions,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discount_kind' => $this->discountKind->value,
            'discount_value' => $this->discountValue,
            'activates_at' => $this->activatesAt->format(DateTimeImmutable::RFC3339),
            'expires_at' => $this->expiresAt->format(DateTimeImmutable::RFC3339),
            'is_active' => $this->isActive,
            'max_uses' => $this->maxUses,
            'times_used' => $this->timesUsed,
            'restaurant_id' => $this->restaurantId,
            'conditions' => array_map(
                static fn (PromotionConditionInput $c): array => [
                    'kind' => $c->kind->value,
                    'operand' => $c->operand,
                ],
                $this->conditions,
            ),
        ];
    }
}
