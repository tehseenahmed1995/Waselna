<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Pricing\Contracts\Services\PromotionService;
use DateTimeImmutable;

/**
 * An Administrator's request to create a Promotion (Requirement 18.4).
 *
 * Framework-free: the Admin surface's Form_Request shapes the raw request into
 * this command, the {@see PromotionService}
 * validates every field against the Requirement 18.4 ranges before anything is
 * written, and the repository persists it. The discount kind and value, the
 * activation window, the optional Restaurant scope, the usage cap, and the
 * eligibility conditions are all carried here.
 */
final readonly class CreatePromotionCommand
{
    /**
     * @param  list<PromotionConditionInput>  $conditions
     */
    public function __construct(
        public string $code,
        public DiscountKind $discountKind,
        public int $discountValue,
        public DateTimeImmutable $activatesAt,
        public DateTimeImmutable $expiresAt,
        public ?string $restaurantId = null,
        public ?int $maxUses = null,
        public array $conditions = [],
    ) {}
}
