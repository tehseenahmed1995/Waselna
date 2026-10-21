<?php

declare(strict_types=1);

namespace App\Modules\Zone\Contracts\Services;

use App\Modules\Zone\Domain\PolygonGeometry;

/**
 * Converts a PolygonGeometry into GeoJSON polygon text (Requirement 5.3).
 *
 * An implementation preserves the order of the linear rings and of the
 * coordinate pairs within each ring, repeats each ring's first coordinate pair
 * as its last, and renders every coordinate value in decimal notation with at
 * most 7 fractional digits. The output always re-parses to a geometry equal to
 * the input (Requirement 5.4) and printing is idempotent after normalisation
 * (Requirement 5.5).
 */
interface GeoJsonPrinter
{
    public function print(PolygonGeometry $geometry): string;
}
