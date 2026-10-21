<?php

declare(strict_types=1);

namespace App\Modules\Admin\Boundary;

use App\Modules\Admin\Contracts\Services\PlatformConfigurationService;
use App\Modules\Admin\Domain\PlatformConfigurationView;
use App\Modules\Admin\Services\PlatformConfigurationServiceImplementation;
use App\Shared\Domain\Money;

/**
 * The read-only Module_Boundary over Platform_Configuration, owned by the Admin
 * module and consumed by Pricing, Dispatch, Account, and Notification
 * (design "Admin_Module"; Requirements 37.9, 37.10, 16.12).
 *
 * The Admin module is the only writer of the `platform_configuration` table
 * (Requirement 37.9). Every other module needs some of its values but must not
 * read the table directly — that would break the ownership rule of Requirement
 * 22 — so each reads through this boundary:
 *
 *  - Pricing reads {@see serviceFeeBasisPoints()} and {@see serviceFeeBounds()}
 *    to build its `ServiceFeePolicy` (Requirement 7.9).
 *  - Dispatch reads {@see offerRadii()} for the initial and maximum Offer_Radius
 *    (Requirements 13.2, 13.5).
 *  - Account reads {@see notificationDefaults()} to seed a new Platform user's
 *    preferences at registration (Requirement 16.12).
 *  - Restaurant order handling reads {@see orderRejectionReasons()} to constrain
 *    a rejection reason (Requirement 12.3).
 *
 * Reads reflect the single configuration row, or sensible Platform defaults when
 * no row has been written yet, so a consumer always receives a usable value
 * (Requirement 37.9: the configuration "applies to every Delivery_API request").
 *
 * The implementation ({@see PlatformConfigurationServiceImplementation})
 * also serves the writable
 * {@see PlatformConfigurationService}; this
 * interface deliberately exposes no mutator so a consumer cannot write through
 * the read seam.
 */
interface PlatformConfigurationReader
{
    /**
     * The configured Service_Fee percentage as integer basis points
     * (1 percent = 100 basis points), in the closed range 0..2500
     * (Requirement 7.9).
     */
    public function serviceFeeBasisPoints(): int;

    /**
     * The absolute Service_Fee floor and ceiling the computed fee is clamped to
     * (Requirement 7.9), as `[Money min, Money max]` with `min <= max`.
     *
     * @return array{0: Money, 1: Money}
     */
    public function serviceFeeBounds(): array;

    /**
     * The initial and maximum Offer_Radii in whole kilometres
     * (Requirements 13.2, 13.5), as `[initialKm, maxKm]` with
     * `1 <= initial <= max <= 20`.
     *
     * @return array{0: int, 1: int}
     */
    public function offerRadii(): array;

    /**
     * The Order rejection reasons a Restaurant may cite (Requirement 12.3).
     *
     * @return array<int, string>
     */
    public function orderRejectionReasons(): array;

    /**
     * The Delivery_Job failure reasons a Courier may cite (Requirement 13.8).
     *
     * The running `platform_configuration` schema carries no dedicated column
     * for these, so this returns the Platform default list; it exists on the
     * boundary now so Dispatch's later integration is a one-line call once a
     * column is added.
     *
     * @return array<int, string>
     */
    public function deliveryFailureReasons(): array;

    /**
     * The default Notification-preference matrix a newly created Platform user
     * holds (Requirement 16.12), keyed `category -> channel -> enabled`.
     *
     * @return array<string, array<string, bool>>
     */
    public function notificationDefaults(): array;

    /**
     * The complete configuration as a single immutable snapshot, for the
     * Administrator read surface.
     */
    public function current(): PlatformConfigurationView;
}
