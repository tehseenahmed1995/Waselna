<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zone module schema: Delivery_Zones and their assignment to Restaurant_Branches
 * (task 6.5; Requirements 5.8, 5.9, 32.11, 40.6).
 *
 * A Delivery_Zone belongs to one Restaurant and stores its geometry twice in the
 * same row: `geometry_geojson` is the authoritative canonical GeoJSON printed by
 * the GeoJSON_Printer, and `geom` is the derived PostGIS geometry indexed with a
 * GIST index so the `ST_Covers` containment query stays indexed. A `ST_IsValid`
 * check keeps a self-intersecting or otherwise invalid polygon out of the table.
 *
 * Zones attach to Restaurant_Branches through the `delivery_zone_branch` pivot,
 * so one zone can serve several branches and a branch's Serviceable_Area is the
 * union of the zones assigned to it (Requirement 32.11).
 *
 * The `geocode_cache` table (task 6.7) records the coordinate a normalized
 * address resolved to, keyed by a unique SHA-256 `address_hash`, so the local
 * deterministic AddressResolver resolves each distinct address once (design Zone
 * notes; Requirements 5.6, 23.8, 43.7).
 *
 * The PostGIS extension is enabled by the preceding `2024_01_07` migration, so
 * the `geometry(Polygon, 4326)` column type resolves here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name', 150);
            // Authoritative canonical GeoJSON printed by the GeoJSON_Printer.
            $table->jsonb('geometry_geojson');
            // Derived, indexed PostGIS geometry used by the ST_Covers query.
            $table->geometry('geom', 'Polygon', 4326);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('restaurant_id');
        });

        // Boundary-inclusive containment is only correct against a valid polygon;
        // reject an invalid one at the database level (Requirement 40.12).
        DB::statement('ALTER TABLE delivery_zones ADD CONSTRAINT delivery_zones_geom_valid_check CHECK (ST_IsValid(geom))');

        // GIST index so ST_Covers uses the spatial index rather than a scan.
        DB::statement('CREATE INDEX delivery_zones_geom_gist ON delivery_zones USING GIST (geom)');

        Schema::create('delivery_zone_branch', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('delivery_zone_id')->constrained('delivery_zones')->cascadeOnDelete();
            $table->foreignUlid('restaurant_branch_id')->constrained('restaurant_branches')->cascadeOnDelete();

            // A zone is assigned to a branch at most once (Requirement 40.3).
            $table->unique(['delivery_zone_id', 'restaurant_branch_id']);
            $table->index('delivery_zone_id');
            $table->index('restaurant_branch_id');
        });

        Schema::create('geocode_cache', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // SHA-256 hex digest of the normalized address, the lookup key.
            $table->string('address_hash', 64);
            // Resolved coordinate, fixed-precision at 7 fractional digits
            // (Requirement 40.7), matching the Coordinate value object.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // The instant the address was resolved, read from the injected Clock.
            $table->timestampTz('resolved_at');

            // One cache row per distinct normalized address (design Zone notes).
            $table->unique('address_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocode_cache');
        Schema::dropIfExists('delivery_zone_branch');
        Schema::dropIfExists('delivery_zones');
    }
};
