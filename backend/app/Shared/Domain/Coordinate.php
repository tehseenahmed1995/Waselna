<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * A WGS 84 coordinate pair held as fixed-precision decimal strings.
 *
 * Requirement 40.7 stores every coordinate as a fixed-precision numeric type
 * carrying at least 7 fractional digits, and Requirement 40.4 restricts
 * latitude to the closed range -90 to 90 and longitude to -180 to 180. Holding
 * the pair as decimal strings normalised to exactly 7 fractional digits is what
 * makes the Zone geometry round-trip of Requirements 5.4 and 5.5 exact rather
 * than approximate.
 *
 * Distance is the straight-line (great-circle) separation in kilometres,
 * rounded half up to 3 fractional digits, which is the input Requirement 7.2
 * feeds into the Delivery_Fee calculation.
 */
final readonly class Coordinate
{
    public const FRACTIONAL_DIGITS = 7;

    public const DISTANCE_FRACTIONAL_DIGITS = 3;

    public const MIN_LATITUDE = '-90';

    public const MAX_LATITUDE = '90';

    public const MIN_LONGITUDE = '-180';

    public const MAX_LONGITUDE = '180';

    /**
     * IUGG mean Earth radius in kilometres.
     */
    private const EARTH_RADIUS_KILOMETRES = 6371.0088;

    public string $latitude;

    public string $longitude;

    public function __construct(string $latitude, string $longitude)
    {
        $this->latitude = self::normalise($latitude, 'latitude', self::MIN_LATITUDE, self::MAX_LATITUDE);
        $this->longitude = self::normalise($longitude, 'longitude', self::MIN_LONGITUDE, self::MAX_LONGITUDE);
    }

    public static function of(string $latitude, string $longitude): self
    {
        return new self($latitude, $longitude);
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude
            && $this->longitude === $other->longitude;
    }

    /**
     * The great-circle distance to $other in kilometres, rounded half up to 3
     * fractional digits (Requirement 7.2).
     *
     * The trigonometry runs in floating point — no exact closed form exists —
     * but the single rounding step at the end makes the returned value
     * reproducible for a given pair, which is what the pricing determinism of
     * Requirement 7.4 depends on.
     */
    public function haversineKilometres(self $other): string
    {
        if ($this->equals($other)) {
            return Decimal::roundHalfUp('0', self::DISTANCE_FRACTIONAL_DIGITS);
        }

        $latitudeFrom = deg2rad((float) $this->latitude);
        $latitudeTo = deg2rad((float) $other->latitude);
        $deltaLatitude = $latitudeTo - $latitudeFrom;
        $deltaLongitude = deg2rad((float) $other->longitude - (float) $this->longitude);

        $chordHalf = sin($deltaLatitude / 2) ** 2
            + cos($latitudeFrom) * cos($latitudeTo) * sin($deltaLongitude / 2) ** 2;

        $centralAngle = 2 * asin(min(1.0, sqrt($chordHalf)));
        $kilometres = self::EARTH_RADIUS_KILOMETRES * $centralAngle;

        return Decimal::roundHalfUp(sprintf('%.9F', $kilometres), self::DISTANCE_FRACTIONAL_DIGITS);
    }

    /**
     * @return array{latitude: string, longitude: string}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /**
     * The GeoJSON ordering: longitude first, latitude second.
     *
     * @return array{0: string, 1: string}
     */
    public function toGeoJsonPosition(): array
    {
        return [$this->longitude, $this->latitude];
    }

    private static function normalise(string $value, string $axis, string $minimum, string $maximum): string
    {
        if (! Decimal::isDecimal($value)) {
            throw new InvalidArgumentException(
                sprintf('A %s must be a decimal number; received "%s".', $axis, $value)
            );
        }

        $normalised = Decimal::roundHalfUp($value, self::FRACTIONAL_DIGITS);

        if (Decimal::compare($normalised, $minimum, self::FRACTIONAL_DIGITS) < 0
            || Decimal::compare($normalised, $maximum, self::FRACTIONAL_DIGITS) > 0) {
            throw new InvalidArgumentException(sprintf(
                'A %s must fall in the closed range %s to %s; received "%s".',
                $axis,
                $minimum,
                $maximum,
                $value,
            ));
        }

        return $normalised;
    }
}
