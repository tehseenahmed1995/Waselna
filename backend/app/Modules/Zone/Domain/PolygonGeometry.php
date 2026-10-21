<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Shared\Domain\Coordinate;
use InvalidArgumentException;

/**
 * An immutable GeoJSON Polygon geometry: an ordered list of linear rings, each
 * an ordered list of Coordinate values, validated at construction against the
 * limits of Requirement 5.1.
 *
 * The invariants held here are the structural half of the Delivery_Zone
 * grammar, independent of the textual grammar the GeoJSON_Parser enforces:
 *
 *  - 1 to 10 linear rings (Requirement 5.1);
 *  - 4 to 1,000 coordinate pairs per ring (Requirement 5.1);
 *  - every ring is closed — its first and last coordinate pairs are equal
 *    (Requirement 5.1).
 *
 * Coordinate range checks (-90..90 latitude, -180..180 longitude) are enforced
 * by Coordinate itself, so a PolygonGeometry can never hold an out-of-range
 * value. Because Coordinate holds decimal strings normalised to 7 fractional
 * digits rather than floats, two geometries parsed from the same text are
 * structurally identical, which is what makes the round-trip properties of
 * Requirements 5.4 and 5.5 exact.
 */
final class PolygonGeometry
{
    public const MIN_RINGS = 1;

    public const MAX_RINGS = 10;

    public const MIN_COORDINATES_PER_RING = 4;

    public const MAX_COORDINATES_PER_RING = 1000;

    /**
     * @var list<list<Coordinate>>
     */
    private array $rings;

    /**
     * @param  array<int, array<int, Coordinate>>  $rings
     */
    public function __construct(array $rings)
    {
        $ringCount = count($rings);

        if ($ringCount < self::MIN_RINGS || $ringCount > self::MAX_RINGS) {
            throw new InvalidArgumentException(sprintf(
                'A polygon holds between %d and %d linear rings; received %d.',
                self::MIN_RINGS,
                self::MAX_RINGS,
                $ringCount,
            ));
        }

        $normalised = [];

        foreach (array_values($rings) as $ringIndex => $ring) {
            $normalised[] = self::assertRing($ring, $ringIndex);
        }

        $this->rings = $normalised;
    }

    /**
     * @param  array<int, array<int, Coordinate>>  $rings
     */
    public static function of(array $rings): self
    {
        return new self($rings);
    }

    /**
     * The linear rings in order; each ring is a list of Coordinate values in
     * order, including the repeated closing pair.
     *
     * @return list<list<Coordinate>>
     */
    public function rings(): array
    {
        return $this->rings;
    }

    public function ringCount(): int
    {
        return count($this->rings);
    }

    /**
     * Structural equality (Requirement 5.4): the same number of rings in the
     * same order, the same number of coordinate pairs in the same order within
     * each ring, and coordinate values identical at 7 fractional digits.
     */
    public function equals(self $other): bool
    {
        if (count($this->rings) !== count($other->rings)) {
            return false;
        }

        foreach ($this->rings as $ringIndex => $ring) {
            $otherRing = $other->rings[$ringIndex];

            if (count($ring) !== count($otherRing)) {
                return false;
            }

            foreach ($ring as $pairIndex => $coordinate) {
                if (! $coordinate->equals($otherRing[$pairIndex])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $ring
     * @return list<Coordinate>
     */
    private static function assertRing(array $ring, int $ringIndex): array
    {
        $ring = array_values($ring);
        $pairCount = count($ring);

        if ($pairCount < self::MIN_COORDINATES_PER_RING || $pairCount > self::MAX_COORDINATES_PER_RING) {
            throw new InvalidArgumentException(sprintf(
                'Linear ring %d holds between %d and %d coordinate pairs; received %d.',
                $ringIndex,
                self::MIN_COORDINATES_PER_RING,
                self::MAX_COORDINATES_PER_RING,
                $pairCount,
            ));
        }

        foreach ($ring as $pairIndex => $coordinate) {
            if (! $coordinate instanceof Coordinate) {
                throw new InvalidArgumentException(sprintf(
                    'Linear ring %d, position %d is not a Coordinate.',
                    $ringIndex,
                    $pairIndex,
                ));
            }
        }

        if (! $ring[0]->equals($ring[$pairCount - 1])) {
            throw new InvalidArgumentException(sprintf(
                'Linear ring %d is not closed; its first and last coordinate pairs differ.',
                $ringIndex,
            ));
        }

        return $ring;
    }
}
