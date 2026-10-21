<?php

declare(strict_types=1);

namespace App\Modules\Zone\Services;

use App\Modules\Zone\Contracts\Services\Containment;
use App\Modules\Zone\Contracts\Services\GeoJsonPrinter;
use App\Modules\Zone\Domain\PolygonGeometry;
use App\Shared\Domain\Coordinate;
use Illuminate\Database\ConnectionInterface;

/**
 * The PostGIS-backed Containment implementation (Requirements 5.8, 5.9, 32.11).
 *
 * The decision is delegated to PostGIS `ST_Covers`, whose closed semantics make
 * a point on any ring boundary count as inside, which is exactly the
 * boundary-inclusive rule of Requirement 5.8. The polygon is handed to PostGIS
 * as canonical GeoJSON printed by the GeoJSON_Printer, so the same geometry the
 * in-memory evaluator sees is the geometry PostGIS tests — the two must agree
 * for every polygon and probe point (design Property 18).
 *
 * This evaluator tests an in-memory PolygonGeometry rather than a stored row, so
 * it is usable both for persisted zones and for the model-based agreement test.
 * The repository holds the indexed `ST_Covers` query over stored `geom` columns
 * for the serviceability path.
 */
final class PostGisContainment implements Containment
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly GeoJsonPrinter $printer,
    ) {}

    public function contains(PolygonGeometry $polygon, Coordinate $point): bool
    {
        $geoJson = $this->printer->print($polygon);

        /** @var object{covered: bool}|null $row */
        $row = $this->connection->selectOne(
            <<<'SQL'
            SELECT ST_Covers(
                ST_SetSRID(ST_GeomFromGeoJSON(?), 4326),
                ST_SetSRID(ST_MakePoint(?, ?), 4326)
            ) AS covered
            SQL,
            [$geoJson, (float) $point->longitude, (float) $point->latitude],
        );

        return $row !== null && (bool) $row->covered;
    }
}
