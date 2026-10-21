<?php

declare(strict_types=1);

namespace App\Modules\Zone\Repositories;

use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Modules\Zone\Contracts\Services\GeoJsonParser;
use App\Modules\Zone\Contracts\Services\GeoJsonPrinter;
use App\Modules\Zone\Domain\PolygonGeometry;
use App\Modules\Zone\Models\DeliveryZone;
use App\Modules\Zone\Models\DeliveryZoneBranch;
use App\Shared\Domain\Coordinate;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;

/**
 * The one place that queries the Zone module's tables (Backend_Layer_Chain,
 * task 6.5). A concrete, Eloquent-backed repository class with no per-repository
 * interface, matching the design's deviation from the Repository_Interface
 * split; the Service_Implementation depends on this class through constructor
 * injection.
 *
 * The repository is the sole holder of PostGIS SQL. It writes the derived `geom`
 * column from the canonical GeoJSON with `ST_GeomFromGeoJSON` whenever a zone's
 * geometry changes, and it answers the boundary-inclusive containment question
 * with `ST_Covers` (Requirement 5.8). Reading geometry back as a PolygonGeometry
 * for the in-memory evaluator goes through the GeoJSON_Parser against the stored
 * canonical GeoJSON, so both containment implementations see the same rings.
 */
final class DeliveryZoneRepository
{
    public function __construct(
        private readonly IdGenerator $idGenerator,
        private readonly GeoJsonParser $parser,
        private readonly GeoJsonPrinter $printer,
    ) {}

    /**
     * Persists a Delivery_Zone, writing both the authoritative GeoJSON and the
     * derived PostGIS geometry in one statement path, and returns its id.
     */
    public function create(string $restaurantId, string $name, PolygonGeometry $geometry): string
    {
        $id = $this->idGenerator->ulid();
        $geoJson = $this->printer->print($geometry);

        DeliveryZone::query()->create([
            'id' => $id,
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'geometry_geojson' => $geoJson,
        ]);

        $this->writeGeometryColumn($id, $geoJson);

        return $id;
    }

    /**
     * Replaces a Delivery_Zone's geometry, keeping the authoritative GeoJSON and
     * the derived PostGIS geometry in step.
     */
    public function replaceGeometry(string $zoneId, PolygonGeometry $geometry): void
    {
        $geoJson = $this->printer->print($geometry);

        DeliveryZone::query()->whereKey($zoneId)->update(['geometry_geojson' => $geoJson]);

        $this->writeGeometryColumn($zoneId, $geoJson);
    }

    public function findById(string $zoneId): ?DeliveryZone
    {
        return DeliveryZone::query()->find($zoneId);
    }

    /**
     * The Delivery_Zone identifiers of a Restaurant, so an assignment can be
     * rejected when a submitted zone does not belong to the Restaurant
     * (Requirement 32.12).
     *
     * @return list<string>
     */
    public function zoneIdsForRestaurant(string $restaurantId): array
    {
        /** @var list<string> $ids */
        $ids = DeliveryZone::query()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * Replaces the complete set of Delivery_Zones assigned to a Restaurant_Branch
     * with $zoneIds, in one transaction so an assignment is all-or-nothing
     * (Requirements 32.11, 40.8).
     *
     * @param  list<string>  $zoneIds
     */
    public function replaceBranchAssignments(string $branchId, array $zoneIds): void
    {
        DB::transaction(function () use ($branchId, $zoneIds): void {
            DeliveryZoneBranch::query()->where('restaurant_branch_id', $branchId)->delete();

            foreach (array_values(array_unique($zoneIds)) as $zoneId) {
                DeliveryZoneBranch::query()->create([
                    'id' => $this->idGenerator->ulid(),
                    'delivery_zone_id' => $zoneId,
                    'restaurant_branch_id' => $branchId,
                ]);
            }
        });
    }

    /**
     * True when at least one Delivery_Zone is assigned to at least one active
     * Restaurant_Branch of $restaurantId (Requirement 59.5, third publication
     * prerequisite). The `delivery_zone_branch` pivot is joined to
     * `restaurant_branches` to restrict to active branches; both the pivot and
     * the join live in the Zone module's query layer.
     */
    public function anyZoneOnActiveBranch(string $restaurantId): bool
    {
        return DeliveryZoneBranch::query()
            ->join('restaurant_branches', 'restaurant_branches.id', '=', 'delivery_zone_branch.restaurant_branch_id')
            ->where('restaurant_branches.restaurant_id', $restaurantId)
            ->where('restaurant_branches.is_active', true)
            ->exists();
    }

    /**
     * The identifiers of the Delivery_Zones assigned to a Restaurant_Branch, in
     * ascending identifier order. Used by the Catalog module through the
     * {@see ZoneAssignmentDirectory} boundary to
     * report a Restaurant_Branch's Serviceable_Area composition (Requirement
     * 32.11).
     *
     * @return list<string>
     */
    public function zoneIdsForBranch(string $branchId): array
    {
        /** @var list<string> $ids */
        $ids = DeliveryZoneBranch::query()
            ->where('restaurant_branch_id', $branchId)
            ->orderBy('delivery_zone_id')
            ->pluck('delivery_zone_id')
            ->all();

        return $ids;
    }

    /**
     * The parsed geometries of every Delivery_Zone assigned to a
     * Restaurant_Branch, in zone-identifier order. Used by the in-memory
     * ray-cast evaluator.
     *
     * @return list<PolygonGeometry>
     */
    public function geometriesForBranch(string $branchId): array
    {
        return $this->parseGeoJsonColumn(
            DeliveryZone::query()
                ->join('delivery_zone_branch', 'delivery_zones.id', '=', 'delivery_zone_branch.delivery_zone_id')
                ->where('delivery_zone_branch.restaurant_branch_id', $branchId)
                ->orderBy('delivery_zones.id')
                ->pluck('delivery_zones.geometry_geojson')
                ->all(),
        );
    }

    /**
     * The parsed geometries of every Delivery_Zone assigned to an active
     * Restaurant_Branch of a Restaurant, forming the Restaurant's
     * Serviceable_Area as their union (Requirement 32.11). Used by the in-memory
     * evaluator and asserted equal to the PostGIS path.
     *
     * @return list<PolygonGeometry>
     */
    public function geometriesForActiveRestaurantBranches(string $restaurantId): array
    {
        return $this->parseGeoJsonColumn(
            DeliveryZone::query()
                ->join('delivery_zone_branch', 'delivery_zones.id', '=', 'delivery_zone_branch.delivery_zone_id')
                ->join('restaurant_branches', 'restaurant_branches.id', '=', 'delivery_zone_branch.restaurant_branch_id')
                ->where('restaurant_branches.restaurant_id', $restaurantId)
                ->where('restaurant_branches.is_active', true)
                ->orderBy('delivery_zones.id')
                ->pluck('delivery_zones.geometry_geojson')
                ->all(),
        );
    }

    /**
     * True when $point lies inside the Serviceable_Area of a Restaurant computed
     * in the database: within at least one Delivery_Zone assigned to an active
     * Restaurant_Branch, boundary inclusive via `ST_Covers` (Requirements 5.8,
     * 32.11).
     */
    public function restaurantCovers(string $restaurantId, Coordinate $point): bool
    {
        /** @var object{covered: bool}|null $row */
        $row = DB::selectOne(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM delivery_zones z
                JOIN delivery_zone_branch zb ON zb.delivery_zone_id = z.id
                JOIN restaurant_branches b ON b.id = zb.restaurant_branch_id
                WHERE z.restaurant_id = ?
                  AND b.is_active = true
                  AND ST_Covers(z.geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            ) AS covered
            SQL,
            [$restaurantId, (float) $point->longitude, (float) $point->latitude],
        );

        return $row !== null && (bool) $row->covered;
    }

    /**
     * The identifiers of the active Restaurant_Branches of a Restaurant whose
     * assigned Delivery_Zones cover $point, in ascending identifier order
     * (Requirements 5.8, 32.9).
     *
     * @return list<string>
     */
    public function servicingBranchIds(string $restaurantId, Coordinate $point): array
    {
        /** @var list<object{id: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
            SELECT DISTINCT b.id AS id
            FROM restaurant_branches b
            JOIN delivery_zone_branch zb ON zb.restaurant_branch_id = b.id
            JOIN delivery_zones z ON z.id = zb.delivery_zone_id
            WHERE b.restaurant_id = ?
              AND b.is_active = true
              AND ST_Covers(z.geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            ORDER BY b.id ASC
            SQL,
            [$restaurantId, (float) $point->longitude, (float) $point->latitude],
        );

        return array_map(static fn (object $row): string => $row->id, $rows);
    }

    /**
     * Derives the PostGIS geometry column from the canonical GeoJSON. The 4326
     * SRID matches the column declaration; the ST_IsValid check constraint
     * rejects a malformed polygon.
     */
    private function writeGeometryColumn(string $zoneId, string $geoJson): void
    {
        DB::update(
            'UPDATE delivery_zones SET geom = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE id = ?',
            [$geoJson, $zoneId],
        );
    }

    /**
     * @param  array<int, mixed>  $geoJsonValues
     * @return list<PolygonGeometry>
     */
    private function parseGeoJsonColumn(array $geoJsonValues): array
    {
        $geometries = [];

        foreach ($geoJsonValues as $geoJson) {
            $geometries[] = $this->parser->parse((string) $geoJson);
        }

        return $geometries;
    }
}
