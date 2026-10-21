<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Repositories;

use App\Modules\Pricing\Domain\ConditionKind;
use App\Modules\Pricing\Domain\Conditions\FirstOrderOnlyCondition;
use App\Modules\Pricing\Domain\Conditions\MinimumSubtotalCondition;
use App\Modules\Pricing\Domain\Conditions\RestaurantCondition;
use App\Modules\Pricing\Domain\DiscountKind;
use App\Modules\Pricing\Domain\EligibilityCondition;
use App\Modules\Pricing\Domain\Promotion as PromotionAggregate;
use App\Modules\Pricing\Models\Promotion as PromotionModel;
use App\Modules\Pricing\Models\PromotionCondition as PromotionConditionModel;
use App\Modules\Pricing\Services\PromotionServiceImplementation;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The one place that queries the Pricing module's `promotions` and
 * `promotion_conditions` tables (Requirements 7.5–7.7, 18.4).
 *
 * Concrete and Eloquent-backed, following the repository deviation the other
 * modules document: the {@see PromotionServiceImplementation}
 * holds the rules, this holds the SQL. The repository is also the boundary
 * between the Eloquent models, which never leave the module, and the
 * framework-free {@see PromotionAggregate} the rest of the module reasons about:
 * {@see findActiveByCode()} and {@see rehydrate()} map a row and its conditions
 * onto the aggregate, and the condition kind drives which concrete
 * {@see EligibilityCondition} is built.
 */
final class PromotionRepository
{
    /**
     * Inserts a Promotion and its conditions. Runs inside the transaction opened
     * by the caller; the identifiers and timestamps are supplied by the service
     * from the injected IdGenerator and Clock.
     *
     * @param  array<string, mixed>  $attributes  the `promotions` column map
     * @param  list<array{id: string, kind: string, operand: int|string|null}>  $conditions
     */
    public function create(array $attributes, array $conditions, DateTimeImmutable $createdAt): PromotionModel
    {
        // The timestamps are folded into the mass-assigned attributes so Eloquent
        // casts the injected Clock instant to the model's Carbon column type,
        // rather than assigning a DateTimeImmutable to the typed property.
        $promotion = new PromotionModel($attributes + [
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $promotion->save();

        foreach ($conditions as $condition) {
            $row = new PromotionConditionModel([
                'id' => $condition['id'],
                'promotion_id' => $promotion->id,
                'kind' => $condition['kind'],
                'operand' => $condition['operand'],
                'created_at' => $createdAt,
            ]);
            $row->save();
        }

        return $promotion;
    }

    /**
     * Whether any Promotion already holds this code, compared case-insensitively
     * (design `UNIQUE(lower(code))`, Requirement 7.6 single-code rule).
     */
    public function codeExists(string $code): bool
    {
        return PromotionModel::query()
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->exists();
    }

    /**
     * The Promotion stored under this code, compared case-insensitively,
     * rehydrated as the framework-free aggregate with its conditions loaded, or
     * null when no code matches (Requirement 7.6).
     */
    public function findByCode(string $code): ?PromotionAggregate
    {
        $model = PromotionModel::query()
            ->with('conditions')
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->first();

        return $model === null ? null : $this->rehydrate($model);
    }

    /**
     * Records one redemption against a Promotion under its cap, locking the row
     * for the duration of the surrounding transaction so a concurrent redemption
     * cannot overshoot the cap. Returns false when the cap is already reached, in
     * which case nothing is written.
     */
    public function recordRedemption(string $promotionId): bool
    {
        $model = PromotionModel::query()
            ->whereKey($promotionId)
            ->lockForUpdate()
            ->first();

        if ($model === null) {
            return false;
        }

        if ($model->max_uses !== null && $model->times_used >= $model->max_uses) {
            return false;
        }

        $model->times_used++;
        $model->save();

        return true;
    }

    /**
     * Maps a loaded `promotions` model and its conditions onto the aggregate.
     */
    private function rehydrate(PromotionModel $model): PromotionAggregate
    {
        /** @var list<EligibilityCondition> $conditions */
        $conditions = [];

        foreach ($model->conditions as $conditionModel) {
            $conditions[] = $this->toCondition($conditionModel);
        }

        return new PromotionAggregate(
            id: $model->id,
            code: $model->code,
            discountKind: DiscountKind::from($model->discount_kind),
            discountValue: $model->discount_value,
            activatesAt: $this->toUtc($model->activates_at),
            expiresAt: $this->toUtc($model->expires_at),
            isActive: $model->is_active,
            maxUses: $model->max_uses,
            timesUsed: $model->times_used,
            restaurantId: $model->restaurant_id,
            conditions: $conditions,
        );
    }

    /**
     * Builds the concrete {@see EligibilityCondition} for a stored condition row.
     */
    private function toCondition(PromotionConditionModel $row): EligibilityCondition
    {
        $operand = $row->operand;

        return match (ConditionKind::from($row->kind)) {
            ConditionKind::MinimumSubtotal => new MinimumSubtotalCondition((int) $operand),
            ConditionKind::Restaurant => new RestaurantCondition((string) $operand),
            ConditionKind::FirstOrderOnly => new FirstOrderOnlyCondition,
        };
    }

    /**
     * Normalises a stored timestamp to a UTC {@see DateTimeImmutable} so the
     * aggregate's window comparison is in one time zone regardless of the
     * driver's return type.
     */
    private function toUtc(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (is_string($value)) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        }

        throw new RuntimeException('A promotion timestamp could not be read as a date.');
    }
}
