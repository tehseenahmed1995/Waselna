<?php

declare(strict_types=1);

namespace App\Modules\Zone\Services;

use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Domain\AddressNormalizer;
use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Modules\Zone\Repositories\GeocodeCacheRepository;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\Clock;

/**
 * The local deterministic AddressResolver bound in the initial implementation
 * phase (design Zone notes; Requirements 5.6, 5.7, 23.8, 43.7).
 *
 * It integrates no paid geocoding service and makes no outbound network call —
 * the same containment-free, in-process posture the Simulated_Payment_Gateway
 * takes for payments. A coordinate is derived from the SHA-256 hash of the
 * normalized address, so the same place always resolves to the same point and a
 * `geocode_cache` row keyed by that hash lets a repeat submission skip the work
 * entirely.
 *
 * Resolution is bounded by {@see AddressResolver::RESOLUTION_BUDGET_MILLISECONDS}:
 * the elapsed time is measured through the injected {@see Clock} and, if it ever
 * exceeds the budget, the address is rejected as unresolvable exactly as a
 * no-match is (Requirement 5.6, 5.7). Reading the budget from the Clock rather
 * than wall time keeps the deadline deterministic under test — the property
 * suite advances a frozen clock to drive the budget-exceeded branch rather than
 * waiting (design "Clock injection"; Requirement 40.7). A documented sentinel
 * address makes the no-match branch of Requirement 5.7 exercisable without a
 * live service, mirroring the gateway simulator's documented inputs.
 */
final class LocalDeterministicAddressResolver implements AddressResolver
{
    /**
     * A first address line equal to this sentinel (case-insensitive, after
     * trimming) forces the no-match branch so Requirement 5.7 is exercisable
     * without a live geocoding service.
     */
    public const UNRESOLVABLE_SENTINEL = 'UNRESOLVABLE';

    public function __construct(
        private readonly GeocodeCacheRepository $cache,
        private readonly Clock $clock,
    ) {}

    public function resolve(PostalAddress $address): Coordinate
    {
        $startedAt = $this->clock->nowUtc();

        if ($this->isUnresolvable($address)) {
            throw AddressNotResolvable::noMatch();
        }

        $hash = AddressNormalizer::hash($address);

        $coordinate = $this->cache->find($hash) ?? $this->derive($hash);

        $this->assertWithinBudget($startedAt);

        $this->cache->store($hash, $coordinate);

        return $coordinate;
    }

    /**
     * The documented no-match sentinel (Requirement 5.7).
     */
    private function isUnresolvable(PostalAddress $address): bool
    {
        return mb_strtolower($address->line1) === mb_strtolower(self::UNRESOLVABLE_SENTINEL);
    }

    /**
     * Maps the address hash deterministically into the valid coordinate ranges
     * (Requirement 5.6): latitude in -90 to 90, longitude in -180 to 180, each
     * normalised to 7 fractional digits by the Coordinate constructor.
     *
     * Two independent slices of the hash feed latitude and longitude so the two
     * axes vary independently, and the fixed modulus keeps every produced value
     * strictly inside the closed ranges.
     */
    private function derive(string $hash): Coordinate
    {
        // Scaled integers in [0, span * 1e7]: a 180-degree latitude span and a
        // 360-degree longitude span at 7 fractional digits.
        $latitudeScaled = hexdec(substr($hash, 0, 8)) % 1_800_000_001;
        $longitudeScaled = hexdec(substr($hash, 8, 8)) % 3_600_000_001;

        // Shift the non-negative magnitudes into the signed closed ranges
        // (-90..90 latitude, -180..180 longitude) through exact integer math.
        $latitude = self::shift(self::scaledToDecimal($latitudeScaled, 7), '-90', 7);
        $longitude = self::shift(self::scaledToDecimal($longitudeScaled, 7), '-180', 7);

        return Coordinate::of($latitude, $longitude);
    }

    /**
     * Builds a decimal string from an integer scaled by 10^$scale without
     * floating point, so the derived coordinate is exact.
     */
    private static function scaledToDecimal(int $scaled, int $scale): string
    {
        $digits = str_pad((string) $scaled, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, strlen($digits) - $scale);
        $fraction = substr($digits, -$scale);

        return $integer.'.'.$fraction;
    }

    /**
     * Adds $offset to $value at $scale, exactly, through integer arithmetic.
     */
    private static function shift(string $value, string $offset, int $scale): string
    {
        $sum = Decimal::toScaledInt($value, $scale) + Decimal::toScaledInt($offset, $scale);
        $negative = $sum < 0;
        $magnitude = self::scaledToDecimal(abs($sum), $scale);

        return $negative ? '-'.$magnitude : $magnitude;
    }

    /**
     * Rejects resolution that ran past the budget (Requirement 5.6). Elapsed
     * time is the difference between two readings of the injected Clock, so the
     * local adapter never trips the budget in practice yet the branch is exactly
     * reproducible under test by advancing a frozen clock. The check holds for
     * any future adapter sharing this budget constant and this Clock.
     */
    private function assertWithinBudget(\DateTimeImmutable $startedAt): void
    {
        // Microsecond instants (`U.u`) subtracted as integers, so the elapsed
        // span is exact and never depends on wall-clock time.
        $startedAtMicroseconds = (int) $startedAt->format('Uu');
        $finishedAtMicroseconds = (int) $this->clock->nowUtc()->format('Uu');

        $elapsedMilliseconds = intdiv($finishedAtMicroseconds - $startedAtMicroseconds, 1_000);

        if ($elapsedMilliseconds > self::RESOLUTION_BUDGET_MILLISECONDS) {
            throw AddressNotResolvable::budgetExceeded(
                $elapsedMilliseconds,
                self::RESOLUTION_BUDGET_MILLISECONDS,
            );
        }
    }
}
