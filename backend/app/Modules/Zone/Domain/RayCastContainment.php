<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Modules\Zone\Contracts\Services\Containment;
use App\Shared\Domain\Coordinate;

/**
 * A framework-free, in-memory containment evaluator (Requirements 5.8, 5.9,
 * 32.11).
 *
 * The decision is boundary-inclusive and is the in-memory twin of the
 * PostGIS `ST_Covers` query. Both must return the same answer for every
 * polygon and probe point (design Property 18), which protects the two
 * implementations against silent divergence.
 *
 * The algorithm, per ring:
 *
 *  1. On-boundary first. If the probe point sits exactly on any edge of any
 *     ring, the point is inside (Requirement 5.8's "exactly on a ring boundary
 *     is inside"). This is tested before the parity rule so a point on an
 *     interior ring's boundary is reported inside rather than excluded.
 *  2. Even-odd parity otherwise. A point is strictly inside a ring when a ray
 *     cast to the east crosses the ring's edges an odd number of times. The
 *     point is inside the polygon when it is strictly inside the exterior ring
 *     and strictly outside every interior ring (Requirement 5.8).
 *
 * The first ring is the exterior ring and every later ring is an interior ring
 * (a hole), matching the GeoJSON polygon convention the GeoJSON_Parser accepts.
 *
 * Coordinates are compared as floats here. Coordinate normalises every value to
 * 7 fractional digits before this evaluator ever sees it, so the float a given
 * stored coordinate produces is fixed, which keeps the decision reproducible.
 * The on-boundary test uses an exact collinear-and-between check rather than a
 * tolerance, so a point generated to lie on an edge is always classified as a
 * boundary point.
 */
final class RayCastContainment implements Containment
{
    public function contains(PolygonGeometry $polygon, Coordinate $point): bool
    {
        $rings = $polygon->rings();

        $pointX = (float) $point->longitude;
        $pointY = (float) $point->latitude;

        // A point on any ring boundary is inside, exterior or interior alike.
        foreach ($rings as $ring) {
            if ($this->liesOnRingBoundary($ring, $pointX, $pointY)) {
                return true;
            }
        }

        $exteriorRing = $rings[0];

        if (! $this->strictlyInsideRing($exteriorRing, $pointX, $pointY)) {
            return false;
        }

        $ringCount = count($rings);

        for ($ringIndex = 1; $ringIndex < $ringCount; $ringIndex++) {
            if ($this->strictlyInsideRing($rings[$ringIndex], $pointX, $pointY)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the point sits exactly on an edge of the ring: collinear with
     * the edge's endpoints and within the edge's bounding box.
     *
     * @param  list<Coordinate>  $ring
     */
    private function liesOnRingBoundary(array $ring, float $pointX, float $pointY): bool
    {
        $vertexCount = count($ring);

        for ($current = 0, $previous = $vertexCount - 1; $current < $vertexCount; $previous = $current++) {
            $startX = (float) $ring[$previous]->longitude;
            $startY = (float) $ring[$previous]->latitude;
            $endX = (float) $ring[$current]->longitude;
            $endY = (float) $ring[$current]->latitude;

            if ($this->liesOnSegment($startX, $startY, $endX, $endY, $pointX, $pointY)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when (pointX, pointY) is collinear with the segment and falls within
     * its bounding box, i.e. exactly on the segment including its endpoints.
     */
    private function liesOnSegment(
        float $startX,
        float $startY,
        float $endX,
        float $endY,
        float $pointX,
        float $pointY,
    ): bool {
        $cross = ($endX - $startX) * ($pointY - $startY) - ($endY - $startY) * ($pointX - $startX);

        if ($cross !== 0.0) {
            return false;
        }

        return $pointX >= min($startX, $endX)
            && $pointX <= max($startX, $endX)
            && $pointY >= min($startY, $endY)
            && $pointY <= max($startY, $endY);
    }

    /**
     * Even-odd ray-cast test: true when a ray from the point to the east
     * crosses the ring's edges an odd number of times. Points exactly on the
     * boundary are excluded here and handled by liesOnRingBoundary.
     *
     * @param  list<Coordinate>  $ring
     */
    private function strictlyInsideRing(array $ring, float $pointX, float $pointY): bool
    {
        $inside = false;
        $vertexCount = count($ring);

        for ($current = 0, $previous = $vertexCount - 1; $current < $vertexCount; $previous = $current++) {
            $currentX = (float) $ring[$current]->longitude;
            $currentY = (float) $ring[$current]->latitude;
            $previousX = (float) $ring[$previous]->longitude;
            $previousY = (float) $ring[$previous]->latitude;

            $straddlesRay = ($currentY > $pointY) !== ($previousY > $pointY);

            if (! $straddlesRay) {
                continue;
            }

            $intersectionX = ($previousX - $currentX) * ($pointY - $currentY) / ($previousY - $currentY) + $currentX;

            if ($pointX < $intersectionX) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
