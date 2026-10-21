<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Modules\Zone\Domain\PolygonGeometry;
use App\Shared\Domain\Coordinate;

/**
 * Decides whether a Coordinate lies inside a single Delivery_Zone polygon
 * (Requirements 5.8, 5.9, 32.11).
 *
 * Containment is boundary-inclusive: a Coordinate lying within the exterior
 * ring and outside every interior ring of the polygon is inside, and a
 * Coordinate lying exactly on any ring boundary is also inside (Requirement
 * 5.8).
 *
 * The contract is implementation-agnostic. Two implementations exist behind it:
 * a PostGIS-backed query using `ST_Covers`, whose closed semantics give the
 * boundary-inclusive behaviour, and a framework-free in-memory ray-cast
 * evaluator. Both must agree for every polygon and probe point, which a
 * model-based property test asserts (design Property 18), so a Service_Impl can
 * be swapped without changing a single containment decision.
 */
interface Containment
{
    /**
     * True when $point lies inside $polygon, inclusive of every ring boundary.
     */
    public function contains(PolygonGeometry $polygon, Coordinate $point): bool;
}
