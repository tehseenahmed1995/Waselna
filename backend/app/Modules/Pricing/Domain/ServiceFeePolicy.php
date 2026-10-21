<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * The Platform's Service_Fee configuration carried into the Pricing_Engine
 * (Requirement 7.9).
 *
 * Service_Fee is a percentage of Line_Subtotal, carried here as integer basis
 * points bounded to the 0 to 25 percent range Requirement 7.9 permits, then
 * clamped to the inclusive minimum and maximum the Platform configures. These
 * values originate in Platform_Configuration and reach the engine through the
 * Admin module's `PlatformConfigurationReader` boundary; holding them in a
 * framework-free value object keeps the engine itself free of any configuration
 * source (design "Pricing module").
 *
 * The minimum and maximum are {@see Money} amounts because they are absolute
 * fee floors and ceilings, not percentages; both must share the Cart currency,
 * which the engine asserts when it applies the policy.
 */
final readonly class ServiceFeePolicy
{
    /**
     * The widest Service_Fee rate Requirement 7.9 allows: 25 percent in basis
     * points.
     */
    public const MAX_SERVICE_FEE_BASIS_POINTS = 2500;

    public string $currency;

    public function __construct(
        public int $basisPoints,
        public Money $minimum,
        public Money $maximum,
    ) {
        if ($basisPoints < 0 || $basisPoints > self::MAX_SERVICE_FEE_BASIS_POINTS) {
            throw new InvalidArgumentException(sprintf(
                'A Service_Fee rate must fall in the closed range 0 to %d basis points; received %d (Requirement 7.9).',
                self::MAX_SERVICE_FEE_BASIS_POINTS,
                $basisPoints,
            ));
        }

        $this->currency = $minimum->currency;

        if ($maximum->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'A Service_Fee policy mixes currencies %s and %s.',
                $this->currency,
                $maximum->currency,
            ));
        }

        if ($minimum->minorUnits > $maximum->minorUnits) {
            throw new InvalidArgumentException(sprintf(
                'A minimum Service_Fee of %d cannot exceed its maximum of %d.',
                $minimum->minorUnits,
                $maximum->minorUnits,
            ));
        }
    }
}
