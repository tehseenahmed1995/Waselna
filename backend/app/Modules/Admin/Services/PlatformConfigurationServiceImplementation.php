<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Boundary\PlatformConfigurationReader;
use App\Modules\Admin\Contracts\Services\PlatformConfigurationService;
use App\Modules\Admin\Domain\PlatformConfigurationDefaults;
use App\Modules\Admin\Domain\PlatformConfigurationView;
use App\Modules\Admin\Domain\UpdateConfigurationCommand;
use App\Modules\Admin\Models\PlatformConfiguration;
use App\Modules\Admin\Repositories\PlatformConfigurationRepository;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Domain\Money;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * The one object behind both Platform_Configuration seams
 * (Requirements 37.9, 37.10):
 *
 *  - the writable {@see PlatformConfigurationService} an Administrator surface
 *    uses to read the current configuration and submit an update, and
 *  - the read-only {@see PlatformConfigurationReader} Pricing, Dispatch,
 *    Account, and Restaurant order handling consume for the values they need.
 *
 * Binding one instance to both interfaces keeps every reader and the writer
 * agreeing on the same stored row at every instant, which is what makes
 * design Property 56 ("Platform_Configuration applies to every request after
 * commit") hold.
 *
 * The service holds the rules, the {@see PlatformConfigurationRepository} holds
 * the SQL. Every value is validated against the Requirement 37.10 ranges before
 * it persists; an out-of-range submission is rejected with `validation_failed`
 * and the stored row is left byte-unchanged. Reads reflect the single stored
 * row or the {@see PlatformConfigurationDefaults} when none exists, so a
 * consumer always receives a usable value.
 *
 * Service_Fee bounds are expressed in the single configured currency
 * ({@see PlatformConfigurationDefaults::CURRENCY}) and persist to the running
 * schema's `delivery_fee_min_minor` / `delivery_fee_max_minor` columns; the
 * typed accessors wrap them as {@see Money} so no consumer sees the raw column
 * name. The identifier of a first-ever write comes only from the injected
 * {@see IdGenerator} and the `updated_at` instant only from the injected
 * {@see Clock}, so a write is deterministic under test.
 */
final class PlatformConfigurationServiceImplementation implements PlatformConfigurationReader, PlatformConfigurationService
{
    /**
     * The inclusive Service_Fee percentage range in basis points
     * (0..2500 = 0..25%), per the design data-model `CHECK` and Requirement 7.9.
     */
    public const SERVICE_FEE_BASIS_POINTS_MIN = 0;

    public const SERVICE_FEE_BASIS_POINTS_MAX = 2500;

    /**
     * The inclusive Offer_Radius range in whole kilometres (Requirement 13.5).
     */
    public const OFFER_RADIUS_KM_MIN = 1;

    public const OFFER_RADIUS_KM_MAX = 20;

    public function __construct(
        private readonly PlatformConfigurationRepository $repository,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    public function current(): PlatformConfigurationView
    {
        $row = $this->repository->current();

        return $row === null
            ? $this->defaults()
            : $this->toView($row);
    }

    public function update(UpdateConfigurationCommand $command): PlatformConfigurationView
    {
        $this->assertValid($command);

        return DB::transaction(function () use ($command): PlatformConfigurationView {
            $row = $this->repository->upsert($this->ids->ulid(), [
                'service_fee_percentage_min_bp' => $command->serviceFeeBasisPoints,
                'service_fee_percentage_max_bp' => $command->serviceFeeBasisPoints,
                'delivery_fee_min_minor' => $command->serviceFeeMinimumMinor,
                'delivery_fee_max_minor' => $command->serviceFeeMaximumMinor,
                'offer_radius_km_default' => $command->initialOfferRadiusKm,
                'offer_radius_km_max' => $command->maxOfferRadiusKm,
                'rejection_reasons' => array_values($command->orderRejectionReasons),
                'notification_defaults' => $command->notificationDefaults,
                'updated_at' => $this->clock->nowUtc(),
            ]);

            return $this->toView($row);
        });
    }

    public function serviceFeeBasisPoints(): int
    {
        return $this->current()->serviceFeeBasisPoints;
    }

    /**
     * @return array{0: Money, 1: Money}
     */
    public function serviceFeeBounds(): array
    {
        $view = $this->current();

        return [$view->serviceFeeMinimum, $view->serviceFeeMaximum];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function offerRadii(): array
    {
        $view = $this->current();

        return [$view->initialOfferRadiusKm, $view->maxOfferRadiusKm];
    }

    /**
     * @return array<int, string>
     */
    public function orderRejectionReasons(): array
    {
        return $this->current()->orderRejectionReasons;
    }

    /**
     * @return array<int, string>
     */
    public function deliveryFailureReasons(): array
    {
        return $this->current()->deliveryFailureReasons;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function notificationDefaults(): array
    {
        return $this->current()->notificationDefaults;
    }

    /**
     * Rejects a submission whose any value falls outside the Requirement 37.10
     * ranges, naming every offending field, before anything is written.
     */
    private function assertValid(UpdateConfigurationCommand $command): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if (
            $command->serviceFeeBasisPoints < self::SERVICE_FEE_BASIS_POINTS_MIN
            || $command->serviceFeeBasisPoints > self::SERVICE_FEE_BASIS_POINTS_MAX
        ) {
            $errors['service_fee_basis_points'][] = sprintf(
                'must be in the closed range %d to %d',
                self::SERVICE_FEE_BASIS_POINTS_MIN,
                self::SERVICE_FEE_BASIS_POINTS_MAX,
            );
        }

        if (
            $command->serviceFeeMinimumMinor < Money::MIN_STORABLE_MINOR_UNITS
            || $command->serviceFeeMinimumMinor > Money::MAX_STORABLE_MINOR_UNITS
        ) {
            $errors['service_fee_min'][] = sprintf(
                'must be in the closed range %d to %d minor units',
                Money::MIN_STORABLE_MINOR_UNITS,
                Money::MAX_STORABLE_MINOR_UNITS,
            );
        }

        if (
            $command->serviceFeeMaximumMinor < Money::MIN_STORABLE_MINOR_UNITS
            || $command->serviceFeeMaximumMinor > Money::MAX_STORABLE_MINOR_UNITS
        ) {
            $errors['service_fee_max'][] = sprintf(
                'must be in the closed range %d to %d minor units',
                Money::MIN_STORABLE_MINOR_UNITS,
                Money::MAX_STORABLE_MINOR_UNITS,
            );
        }

        if (
            ! isset($errors['service_fee_min'], $errors['service_fee_max'])
            && $command->serviceFeeMinimumMinor > $command->serviceFeeMaximumMinor
        ) {
            $errors['service_fee_max'][] = 'must be greater than or equal to the minimum';
        }

        if (
            $command->initialOfferRadiusKm < self::OFFER_RADIUS_KM_MIN
            || $command->initialOfferRadiusKm > self::OFFER_RADIUS_KM_MAX
        ) {
            $errors['initial_offer_radius_km'][] = sprintf(
                'must be in the closed range %d to %d kilometres',
                self::OFFER_RADIUS_KM_MIN,
                self::OFFER_RADIUS_KM_MAX,
            );
        }

        if (
            $command->maxOfferRadiusKm < self::OFFER_RADIUS_KM_MIN
            || $command->maxOfferRadiusKm > self::OFFER_RADIUS_KM_MAX
        ) {
            $errors['max_offer_radius_km'][] = sprintf(
                'must be in the closed range %d to %d kilometres',
                self::OFFER_RADIUS_KM_MIN,
                self::OFFER_RADIUS_KM_MAX,
            );
        }

        if (
            ! isset($errors['initial_offer_radius_km'], $errors['max_offer_radius_km'])
            && $command->initialOfferRadiusKm > $command->maxOfferRadiusKm
        ) {
            $errors['max_offer_radius_km'][] = 'must be greater than or equal to the initial radius';
        }

        if ($command->orderRejectionReasons === []) {
            $errors['order_rejection_reasons'][] = 'must list at least one reason';
        } else {
            foreach ($command->orderRejectionReasons as $reason) {
                if (trim($reason) === '') {
                    $errors['order_rejection_reasons'][] = 'must not contain an empty reason';

                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::failed(
                $errors,
                'The Platform_Configuration update did not pass validation.',
            );
        }
    }

    private function toView(PlatformConfiguration $row): PlatformConfigurationView
    {
        $currency = PlatformConfigurationDefaults::CURRENCY;

        /** @var array<int, string> $rejectionReasons */
        $rejectionReasons = $row->rejection_reasons ?? PlatformConfigurationDefaults::REJECTION_REASONS;

        /** @var array<string, array<string, bool>> $notificationDefaults */
        $notificationDefaults = $this->normaliseNotificationDefaults($row->notification_defaults);

        return new PlatformConfigurationView(
            serviceFeeBasisPoints: $row->service_fee_percentage_min_bp,
            serviceFeeMinimum: Money::ofMinorUnits($row->delivery_fee_min_minor, $currency),
            serviceFeeMaximum: Money::ofMinorUnits($row->delivery_fee_max_minor, $currency),
            initialOfferRadiusKm: $row->offer_radius_km_default,
            maxOfferRadiusKm: $row->offer_radius_km_max,
            orderRejectionReasons: array_values($rejectionReasons),
            deliveryFailureReasons: PlatformConfigurationDefaults::REJECTION_REASONS,
            notificationDefaults: $notificationDefaults,
        );
    }

    private function defaults(): PlatformConfigurationView
    {
        $currency = PlatformConfigurationDefaults::CURRENCY;

        return new PlatformConfigurationView(
            serviceFeeBasisPoints: PlatformConfigurationDefaults::SERVICE_FEE_PERCENTAGE_MIN_BP,
            serviceFeeMinimum: Money::ofMinorUnits(PlatformConfigurationDefaults::DELIVERY_FEE_MIN_MINOR, $currency),
            serviceFeeMaximum: Money::ofMinorUnits(PlatformConfigurationDefaults::DELIVERY_FEE_MAX_MINOR, $currency),
            initialOfferRadiusKm: PlatformConfigurationDefaults::OFFER_RADIUS_KM_DEFAULT,
            maxOfferRadiusKm: PlatformConfigurationDefaults::OFFER_RADIUS_KM_MAX,
            orderRejectionReasons: PlatformConfigurationDefaults::REJECTION_REASONS,
            deliveryFailureReasons: PlatformConfigurationDefaults::REJECTION_REASONS,
            notificationDefaults: PlatformConfigurationDefaults::notificationDefaults(),
        );
    }

    /**
     * Coerces a stored, possibly-null `notification_defaults` payload into the
     * `category -> channel -> bool` matrix, falling back to the Platform default
     * when the column is empty so a consumer never receives a malformed map.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, array<string, bool>>
     */
    private function normaliseNotificationDefaults(?array $stored): array
    {
        if ($stored === null || $stored === []) {
            return PlatformConfigurationDefaults::notificationDefaults();
        }

        /** @var array<string, array<string, bool>> $matrix */
        $matrix = [];

        foreach ($stored as $category => $channels) {
            if (! is_array($channels)) {
                continue;
            }

            foreach ($channels as $channel => $enabled) {
                $matrix[(string) $category][(string) $channel] = (bool) $enabled;
            }
        }

        return $matrix === [] ? PlatformConfigurationDefaults::notificationDefaults() : $matrix;
    }
}
