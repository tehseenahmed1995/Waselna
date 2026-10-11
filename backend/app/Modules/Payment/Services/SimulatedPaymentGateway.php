<?php

declare(strict_types=1);

namespace App\Modules\Payment\Services;

/**
 * The deterministic in-process Payment_Gateway used by the Local_Dev_Environment
 * (Requirement 36.2). It makes no outbound call. The outcome is derived from the
 * gateway Idempotency_Key so the same input always yields the same result.
 *
 * Documented simulator inputs (by amount in minor units):
 *   - amount ending in 11 → declined
 *   - amount ending in 22 → gateway error (failed)
 *   - everything else      → authorized + captured
 */
final class SimulatedPaymentGateway
{
    public const OUTCOME_CAPTURED = 'captured';

    public const OUTCOME_DECLINED = 'declined';

    public const OUTCOME_FAILED = 'failed';

    /**
     * @return array{outcome: string, transaction_id: string}
     */
    public function authorizeAndCapture(string $idempotencyKey, int $amountMinor, string $currency): array
    {
        $outcome = match ($amountMinor % 100) {
            11 => self::OUTCOME_DECLINED,
            22 => self::OUTCOME_FAILED,
            default => self::OUTCOME_CAPTURED,
        };

        return [
            'outcome' => $outcome,
            'transaction_id' => 'sim_'.substr(hash('sha256', $idempotencyKey.$amountMinor.$currency), 0, 24),
        ];
    }
}
