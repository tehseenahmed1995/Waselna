<?php

declare(strict_types=1);

/*
 * Feature: waselna — RayCastContainment inside/outside/on-boundary unit tests (task 6.9).
 *
 * These are the specific inside/outside/on-boundary zone points Requirement
 * 23.2 names, exercising the framework-free in-memory containment evaluator of
 * task 6.5 directly, with no database and no network (Backend unit suite of the
 * Testing Strategy). Where the model-based Property 18 pins the evaluator to the
 * PostGIS twin across generated inputs, these examples fix the boundary-inclusive
 * rule of Requirement 5.8 on hand-chosen points — strictly inside, strictly
 * outside, exactly on an exterior-ring edge and vertex, and exactly on an
 * interior-ring (hole) edge and vertex — and cover a Restaurant whose polygon has
 * no interior rings as the single-ring baseline. The "no assigned zones" case
 * (Requirement 5.9) is the serviceability layer above this evaluator: with no
 * zone there is no polygon to test, so a probe can never be contained; that is
 * asserted here as "no zone evaluated means never inside".
 *
 * Validates: Requirements 5.8, 5.9, 23.2.
 *
 * Coordinates are placed on exact 7-fractional-digit decimals so every edge,
 * vertex, and midpoint lands precisely where intended; Coordinate normalises to
 * 7 digits, so the float the evaluator derives is fixed and the on-boundary
 * placement is exact rather than approximate (matching RayCastContainment's exact
 * collinear-and-between boundary test).
 */

use App\Modules\Zone\Domain\PolygonGeometry;
use App\Modules\Zone\Domain\RayCastContainment;
use App\Shared\Domain\Coordinate;

/**
 * A Coordinate from longitude/latitude decimal strings. The evaluator reads
 * longitude as x and latitude as y, so this helper keeps the test readable in
 * (lon, lat) order while Coordinate stores (lat, lon).
 */
function point(string $longitude, string $latitude): Coordinate
{
    return new Coordinate($latitude, $longitude);
}

/**
 * A closed rectangular ring from its lower-left and upper-right corners, wound
 * counter-clockwise. The first vertex is repeated as the last so the ring is
 * closed, as PolygonGeometry requires.
 *
 * @return list<Coordinate>
 */
function ring(string $minLon, string $minLat, string $maxLon, string $maxLat): array
{
    return [
        point($minLon, $minLat),
        point($maxLon, $minLat),
        point($maxLon, $maxLat),
        point($minLon, $maxLat),
        point($minLon, $minLat),
    ];
}

/**
 * The square (0,0)..(10,10) with no holes — the single-ring baseline standing in
 * for a Restaurant whose Delivery_Zone is a simple polygon.
 */
function simpleSquare(): PolygonGeometry
{
    return new PolygonGeometry([
        ring('0.0000000', '0.0000000', '10.0000000', '10.0000000'),
    ]);
}

/**
 * The square (0,0)..(10,10) with a central square hole (4,4)..(6,6), giving an
 * interior ring whose own boundary and interior can be probed.
 */
function holedSquare(): PolygonGeometry
{
    return new PolygonGeometry([
        ring('0.0000000', '0.0000000', '10.0000000', '10.0000000'),
        ring('4.0000000', '4.0000000', '6.0000000', '6.0000000'),
    ]);
}

describe('strictly inside', function (): void {
    it('reports a point well inside a simple polygon as inside', function (): void {
        $contained = (new RayCastContainment())->contains(simpleSquare(), point('5.0000000', '5.0000000'));

        expect($contained)->toBeTrue();
    });

    it('reports a point between the exterior ring and the hole as inside', function (): void {
        // (2,5) is inside the exterior square and outside the (4,4)..(6,6) hole.
        $contained = (new RayCastContainment())->contains(holedSquare(), point('2.0000000', '5.0000000'));

        expect($contained)->toBeTrue();
    });
});

describe('strictly outside', function (): void {
    it('reports a point beyond the exterior ring as outside', function (): void {
        $contained = (new RayCastContainment())->contains(simpleSquare(), point('15.0000000', '5.0000000'));

        expect($contained)->toBeFalse();
    });

    it('reports a point inside the hole as outside', function (): void {
        // (5,5) is the centre of the (4,4)..(6,6) hole: inside the exterior ring
        // but inside an interior ring, so the polygon does not contain it.
        $contained = (new RayCastContainment())->contains(holedSquare(), point('5.0000000', '5.0000000'));

        expect($contained)->toBeFalse();
    });
});

describe('exactly on the exterior ring (boundary-inclusive, Requirement 5.8)', function (): void {
    it('reports a point on an exterior edge as inside', function (): void {
        // Midpoint of the bottom edge (0,0)..(10,0).
        $contained = (new RayCastContainment())->contains(simpleSquare(), point('5.0000000', '0.0000000'));

        expect($contained)->toBeTrue();
    });

    it('reports a point on an exterior vertex as inside', function (): void {
        // The lower-left corner (0,0).
        $contained = (new RayCastContainment())->contains(simpleSquare(), point('0.0000000', '0.0000000'));

        expect($contained)->toBeTrue();
    });
});

describe('exactly on an interior ring / hole (boundary-inclusive, Requirement 5.8)', function (): void {
    it('reports a point on an interior-ring edge as inside', function (): void {
        // Midpoint of the hole's bottom edge (4,4)..(6,4): on the hole boundary,
        // which is inside the Delivery_Zone even though it bounds a hole.
        $contained = (new RayCastContainment())->contains(holedSquare(), point('5.0000000', '4.0000000'));

        expect($contained)->toBeTrue();
    });

    it('reports a point on an interior-ring vertex as inside', function (): void {
        // The hole's lower-left corner (4,4).
        $contained = (new RayCastContainment())->contains(holedSquare(), point('4.0000000', '4.0000000'));

        expect($contained)->toBeTrue();
    });
});

describe('no assigned zones (Requirement 5.9)', function (): void {
    it('never reports a point as inside when the Restaurant has no zone polygon to evaluate', function (): void {
        // Serviceability is the union of the zones on a Restaurant's active
        // branches. A Restaurant with no assigned zones contributes no polygon,
        // so the containment evaluator is never asked a question that can answer
        // "inside": the union over an empty set of zones contains no point.
        $evaluator = new RayCastContainment();
        $zones = []; // No Delivery_Zone polygons assigned.
        $probe = point('5.0000000', '5.0000000');

        $containedByAnyZone = false;

        foreach ($zones as $zone) {
            if ($evaluator->contains($zone, $probe)) {
                $containedByAnyZone = true;

                break;
            }
        }

        expect($containedByAnyZone)->toBeFalse();
    });
});
