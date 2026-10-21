<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Contracts\Services\PromotionService;
use App\Modules\Pricing\Domain\ConditionKind;
use App\Modules\Pricing\Domain\CreatePromotionCommand;
use App\Modules\Pricing\Domain\Promotion;
use App\Modules\Pricing\Domain\PromotionConditionInput;
use App\Modules\Pricing\Domain\PromotionContext;
use App\Modules\Pricing\Domain\PromotionEvaluation;
use App\Modules\Pricing\Domain\PromotionView;
use App\Modules\Pricing\Repositories\PromotionRepository;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Domain\Money;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Promotion creation and evaluation (Requirements 7.5–7.7, 18.4).
 *
 * The service holds the rules; the {@see PromotionRepository} holds the SQL.
 * Creation validates the discount value against the Requirement 18.4 range for
 * its kind, the activation window ordering, and each condition before anything
 * persists, rejecting a bad submission with `validation_failed` and writing
 * nothing. Evaluation reads the current instant only from the injected
 * {@see Clock} — never wall-clock time — so a Promotion window decision is
 * deterministic under test (design "Clock and IdGenerator").
 *
 * Evaluation enforces the Requirement 7.6 single-code rule, rejects an unknown,
 * out-of-window, usage-exhausted, or ineligible code with
 * `promotion_not_applicable`, and otherwise returns the Promotion's discount
 * clamped to `[0, Line_Subtotal + Delivery_Fee]` (Requirement 7.7). The clamp is
 * the same {@see Money::clamp()} the {@see PricingEngineImplementation} applies,
 * so the discount the engine deducts equals the one this service reports.
 */
final class PromotionServiceImplementation implements PromotionService
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    public function create(CreatePromotionCommand $command): PromotionView
    {
        $this->assertValid($command);

        return DB::transaction(function () use ($command): PromotionView {
            // The single-code rule rejects a duplicate code case-insensitively
            // before the insert, so the unique index is a backstop, not the
            // primary message source (Requirement 7.6).
            if ($this->promotions->codeExists($command->code)) {
                throw ValidationException::failed(
                    ['code' => ['A Promotion already exists for this code.']],
                    'The Promotion could not be created.',
                );
            }

            $now = $this->clock->nowUtc();
            $id = $this->ids->ulid();

            $conditionRows = array_map(
                fn (PromotionConditionInput $input): array => [
                    'id' => $this->ids->ulid(),
                    'kind' => $input->kind->value,
                    'operand' => $input->operand,
                ],
                $command->conditions,
            );

            $this->promotions->create(
                attributes: [
                    'id' => $id,
                    'code' => $command->code,
                    'restaurant_id' => $command->restaurantId,
                    'discount_kind' => $command->discountKind->value,
                    'discount_value' => $command->discountValue,
                    'activates_at' => $command->activatesAt,
                    'expires_at' => $command->expiresAt,
                    'is_active' => true,
                    'max_uses' => $command->maxUses,
                    'times_used' => 0,
                ],
                conditions: $conditionRows,
                createdAt: $now,
            );

            // Build the view from the submitted command rather than re-reading,
            // so the returned projection is independent of the storage round-trip.
            return new PromotionView(
                id: $id,
                code: $command->code,
                discountKind: $command->discountKind,
                discountValue: $command->discountValue,
                activatesAt: $command->activatesAt,
                expiresAt: $command->expiresAt,
                isActive: true,
                maxUses: $command->maxUses,
                timesUsed: 0,
                restaurantId: $command->restaurantId,
                conditions: $command->conditions,
            );
        });
    }

    public function evaluate(string $code, PromotionContext $context): PromotionEvaluation
    {
        $promotion = $this->promotions->findByCode($code);

        // An unknown code is rejected with the Promotion-not-applicable code and
        // yields no discount (Requirement 7.6).
        if ($promotion === null) {
            throw $this->notApplicable($code);
        }

        $now = $this->clock->nowUtc();

        // Inactive, out-of-window, usage-exhausted, Restaurant-scoped-away, or
        // condition-failing: every ineligibility collapses to the same rejection,
        // never revealing which cause applied (Requirement 7.6).
        if (! $promotion->isApplicableAt($now, $context)) {
            throw $this->notApplicable($code);
        }

        $discount = $promotion->discountFor($context)->clamp(
            Money::zero($context->currency),
            $context->discountableBase(),
        );

        return new PromotionEvaluation(
            promotionId: $promotion->id,
            code: $promotion->code,
            discountAmount: $discount,
        );
    }

    /**
     * The uniform `promotion_not_applicable` rejection of Requirement 7.6:
     * HTTP 422, Discount_Amount 0, Cart unchanged.
     */
    private function notApplicable(string $code): ValidationException
    {
        return ValidationException::because(
            'promotion_not_applicable',
            'The Promotion code is not applicable to this Cart.',
            ['code' => $code],
        );
    }

    /**
     * Rejects an out-of-range or malformed creation command, naming every
     * offending field, before anything is written (Requirement 18.4).
     */
    private function assertValid(CreatePromotionCommand $command): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if (trim($command->code) === '') {
            $errors['code'][] = 'must not be empty';
        }

        if (! $command->discountKind->permits($command->discountValue)) {
            $errors['discount_value'][] = sprintf(
                'must be in the closed range %d to %d for a %s discount',
                $command->discountKind->minimumValue(),
                $command->discountKind->maximumValue(),
                $command->discountKind->value,
            );
        }

        if ($command->activatesAt >= $command->expiresAt) {
            $errors['expires_at'][] = 'must be later than the activation timestamp';
        }

        if ($command->maxUses !== null && $command->maxUses < 1) {
            $errors['max_uses'][] = 'must be at least 1 when set';
        }

        foreach ($command->conditions as $index => $condition) {
            $error = $this->conditionError($condition);

            if ($error !== null) {
                $errors['conditions.'.$index][] = $error;
            }
        }

        if ($errors !== []) {
            throw ValidationException::failed(
                $errors,
                'The Promotion did not pass validation.',
            );
        }
    }

    /**
     * Validates one condition's operand against its kind, returning the rule it
     * violates or null when it is well formed.
     */
    private function conditionError(PromotionConditionInput $condition): ?string
    {
        return match ($condition->kind) {
            ConditionKind::MinimumSubtotal => (is_int($condition->operand) && $condition->operand >= 0)
                ? null
                : 'minimum-subtotal operand must be a non-negative integer of minor units',
            ConditionKind::Restaurant => (is_string($condition->operand) && $condition->operand !== '')
                ? null
                : 'restaurant operand must be a non-empty Restaurant identifier',
            ConditionKind::FirstOrderOnly => $condition->operand === null
                ? null
                : 'first-order-only condition carries no operand',
        };
    }

    /**
     * Rehydrates a Promotion by code for callers that need the aggregate rather
     * than an evaluation; used by tests and future redemption recording.
     */
    public function findByCode(string $code): ?Promotion
    {
        return $this->promotions->findByCode($code);
    }
}
