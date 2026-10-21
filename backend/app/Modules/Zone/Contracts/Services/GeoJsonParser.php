<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Modules\Zone\Domain\GeoJsonViolation;
use App\Modules\Zone\Domain\PolygonGeometry;

/**
 * Converts GeoJSON polygon text into an immutable PolygonGeometry
 * (Requirement 5.1).
 *
 * An implementation accepts exactly the conforming grammar of Requirement 5.1
 * and, on the first grammar or bound violation, raises a GeoJsonViolation
 * carrying the zero-based character offset of that violation (Requirement 5.2),
 * leaving every stored Delivery_Zone unchanged.
 */
interface GeoJsonParser
{
    /**
     * @throws GeoJsonViolation on the first violation, carrying its offset
     */
    public function parse(string $text): PolygonGeometry;
}
