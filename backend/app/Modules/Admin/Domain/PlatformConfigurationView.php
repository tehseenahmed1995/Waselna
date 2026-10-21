<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

use App\Modules\Admin\Contracts\Services\PlatformConfigurationService;
use App\Shared\Domain\Money;

/**
 * An immutable, framework-free snapshot of Platform_Configuration
 * (Requirements 37.9, 37.10).
 *
 * The Admin module's write service returns this view from
 * {@see PlatformConfigurationService::current()}
 * and the reader builds it once per read, so consumers depend on typed values
 * rather than on the Eloquent model or the raw column names. Monetary bounds are
 * {@see Money}, Offer_Radii are whole kilometres, and the reason and
 * Notification lists are plain arrays.
 */
final readonly class PlatformConfigurationView
{
    /**
     * @param  array<int, string>  $orderRejectionReasons
     * @param  array<int, string>  $deliveryFailureReasons
     * @param  array<string, array<string, bool>>  $notificationDefaults
     */
    public function __construct(
        public int $serviceFeeBasisPoints,
        public Money $serviceFeeMinimum,
        public Money $serviceFeeMaximum,
        public int $initialOfferRadiusKm,
        public int $maxOfferRadiusKm,
        public array $orderRejectionReasons,
        public array $deliveryFailureReasons,
        public array $notificationDefaults,
    ) {}

    /**
     * The Requirement 20.9 representation for an Administrator read surface:
     * primitive values plus the shared Money envelope for the fee bounds.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_fee_basis_points' => $this->serviceFeeBasisPoints,
            'service_fee_min' => $this->serviceFeeMinimum->toArray(),
            'service_fee_max' => $this->serviceFeeMaximum->toArray(),
            'initial_offer_radius_km' => $this->initialOfferRadiusKm,
            'max_offer_radius_km' => $this->maxOfferRadiusKm,
            'order_rejection_reasons' => array_values($this->orderRejectionReasons),
            'delivery_failure_reasons' => array_values($this->deliveryFailureReasons),
            'notification_defaults' => $this->notificationDefaults,
        ];
    }
}
