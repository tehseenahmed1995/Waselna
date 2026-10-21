<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

use App\Shared\Domain\Money;

/**
 * An Administrator's Platform_Configuration update (Requirements 37.9, 37.10).
 *
 * The command carries the complete set of configurable values; the write
 * service validates every field against the Requirement 37.10 ranges before it
 * persists, rejecting an out-of-range submission with `validation_failed` and
 * leaving the stored configuration unchanged. Service_Fee bounds are plain
 * integer minor units here rather than {@see Money} because
 * the command sits at the request edge; the service wraps them in Money against
 * the configured currency once validated.
 */
final readonly class UpdateConfigurationCommand
{
    /**
     * @param  array<int, string>  $orderRejectionReasons
     * @param  array<string, array<string, bool>>  $notificationDefaults
     */
    public function __construct(
        public int $serviceFeeBasisPoints,
        public int $serviceFeeMinimumMinor,
        public int $serviceFeeMaximumMinor,
        public int $initialOfferRadiusKm,
        public int $maxOfferRadiusKm,
        public array $orderRejectionReasons,
        public array $notificationDefaults,
    ) {}
}
