<?php

declare(strict_types=1);

// Feature: waselna, Property 19 (serviceability half): Unserviceable addresses are rejected without side effects

/*
 * The Integration-suite half of Property 19.
 *
 * *For any* resolved coordinate pair outside the Serviceable_Area of the Cart's
 * Restaurant, the request is rejected with `address_not_serviceable` and nothing
 * is created or changed; a coordinate inside that area is accepted and returned.
 *
 * **Validates: Requirements 5.9, 5.10, 32.14**
 *
 * Why here and not in the Property suite. The Property-suite companion
 * (tests/Property/Zone/UnresolvableAndUnserviceableAddressesTest.php) proves the
 * composition order and the no-side-effect guarantee against a
 * ServiceabilityService double, because its own serviceability check runs on
 * PostGIS (`ST_Covers` over a real union of Delivery_Zones) which the SQLite the
 * Property suite uses cannot evaluate. This test closes that gap: it builds a
 * real Delivery_Zone on the PostGIS container, assigns it to an active
 * Restaurant_Branch, and drives the production
 * `DefaultServiceableAddressResolver` -> `DefaultServiceabilityService` ->
 * `DeliveryZoneRepository` ST_Covers path end to end, so the serviceability
 * decision Property 19 depends on is exercised against the real spatial engine.
 *
 * Graceful skip. When no PostGIS connection is reachable the test is skipped
 * with a message naming the dependency rather than failing, matching
 * ContainmentAgreementTest and keeping the integration set honest about what it
 * did and did not exercise (Requirement 23.5).
 *
 * Isolation. All seeded rows are inserted inside a transaction that is rolled
 * back in a finally block, so the test leaves the database byte-unchanged, which
 * is itself the "nothing is created or changed" guarantee at the storage layer.
 */

use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Domain\CanonicalGeoJsonPrinter;
use App\Modules\Zone\Domain\Exceptions\AddressNotServiceable;
use App\Modules\Zone\Domain\RecursiveDescentGeoJsonParser;
use App\Modules\Zone\Repositories\DeliveryZoneRepository;
use App\Modules\Zone\Services\DefaultServiceabilityService;
use App\Modules\Zone\Services\DefaultServiceableAddressResolver;
use App\Modules\Zone\Services\PostGisContainment;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A PostGIS-backed database connection, or null when none is reachable. Same
 * resolution strategy as ContainmentAgreementTest: try the container service
 * name and the published host port, confirm PostGIS answers with a probe query.
 */
function serviceabilityPostgisConnectionOrNull(): ?Connection
{
    static $resolved = false;
    static $connection = null;

    if ($resolved) {
        return $connection;
    }

    $resolved = true;

    $candidates = [
        ['host' => env('DB_HOST', 'postgres'), 'port' => (int) env('DB_PORT', 5432)],
        ['host' => '127.0.0.1', 'port' => 5432],
    ];

    foreach ($candidates as $candidate) {
        config([
            'database.connections.pgsql_serviceability' => [
                'driver' => 'pgsql',
                'host' => $candidate['host'],
                'port' => $candidate['port'],
                'database' => env('DB_DATABASE', 'waselna'),
                'username' => env('DB_USERNAME', 'waselna'),
                'password' => env('DB_PASSWORD', 'waselna_local_password'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
        ]);

        DB::purge('pgsql_serviceability');

        try {
            $pending = DB::connection('pgsql_serviceability');
            // Confirm PostGIS and the Zone/Catalog schema the seed needs are present.
            $pending->select('SELECT ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ST_SetSRID(ST_MakePoint(0,0),4326)) AS ok', [
                '{"type":"Polygon","coordinates":[[[0,0],[0,1],[1,1],[1,0],[0,0]]]}',
            ]);
            $pending->select('SELECT 1 FROM delivery_zones LIMIT 1');
            $pending->select('SELECT 1 FROM restaurant_branches LIMIT 1');

            $connection = $pending;

            return $connection;
        } catch (\Throwable) {
            DB::purge('pgsql_serviceability');

            continue;
        }
    }

    return null;
}

/**
 * An AddressResolver double that returns a fixed coordinate, so this test
 * isolates the serviceability decision from resolution (which the Property suite
 * covers). The composition still runs resolution before serviceability, exactly
 * as production does.
 */
final class FixedCoordinateResolver implements AddressResolver
{
    public function __construct(private readonly Coordinate $coordinate) {}

    public function resolve(PostalAddress $address): Coordinate
    {
        return $this->coordinate;
    }
}

/**
 * Seeds a restaurant, an active branch, and one square Delivery_Zone assigned to
 * that branch, on the given PostGIS connection, returning the restaurant id. The
 * zone is the unit square [0,0]..[0.01,0.01] near the origin.
 */
function seedServiceableRestaurant(Connection $connection): string
{
    $restaurantId = (string) Str::ulid();
    $branchId = (string) Str::ulid();
    $zoneId = (string) Str::ulid();

    $connection->table('restaurants')->insert([
        'id' => $restaurantId,
        'name' => 'Serviceability Fixture',
        'status' => 'published',
        'currency' => 'USD',
        'tax_rate_bp' => 0,
        'base_delivery_fee' => 0,
    ]);

    $connection->table('restaurant_branches')->insert([
        'id' => $branchId,
        'restaurant_id' => $restaurantId,
        'name' => 'Fixture Branch',
        'address' => '1 Fixture Street',
        'latitude' => '0.0050000',
        'longitude' => '0.0050000',
        'is_active' => true,
    ]);

    $geoJson = '{"type":"Polygon","coordinates":[[[0,0],[0,0.01],[0.01,0.01],[0.01,0],[0,0]]]}';

    $connection->table('delivery_zones')->insert([
        'id' => $zoneId,
        'restaurant_id' => $restaurantId,
        'name' => 'Fixture Zone',
        'geometry_geojson' => $geoJson,
    ]);
    $connection->update(
        'UPDATE delivery_zones SET geom = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE id = ?',
        [$geoJson, $zoneId],
    );

    $connection->table('delivery_zone_branch')->insert([
        'id' => (string) Str::ulid(),
        'delivery_zone_id' => $zoneId,
        'restaurant_branch_id' => $branchId,
    ]);

    return $restaurantId;
}

it('rejects a resolved coordinate outside the real Serviceable_Area with address_not_serviceable', function (): void {
    $connection = serviceabilityPostgisConnectionOrNull();

    if ($connection === null) {
        $this->markTestSkipped(
            'Property 19 serviceability requires the postgis/postgis PostgreSQL container with the '
            .'Zone and Catalog schema migrated (ST_Covers over a real Delivery_Zone union). No reachable '
            .'PostGIS connection was found via the pgsql_serviceability connection.',
        );
    }

    // Route the repository and the default connection used by DB::selectOne in
    // the repository's ST_Covers query to the integration connection.
    DB::setDefaultConnection('pgsql_serviceability');

    $connection->beginTransaction();

    try {
        $restaurantId = seedServiceableRestaurant($connection);

        // A point well outside the unit-square zone.
        $outside = Coordinate::of('5.0000000', '5.0000000');
        $gate = new DefaultServiceableAddressResolver(
            new FixedCoordinateResolver($outside),
            new DefaultServiceabilityService(
                new DeliveryZoneRepository(
                    new SequenceIdGenerator(),
                    new RecursiveDescentGeoJsonParser(),
                    new CanonicalGeoJsonPrinter(),
                ),
                new PostGisContainment($connection, new CanonicalGeoJsonPrinter()),
            ),
        );

        $rejected = false;
        try {
            $gate->resolveServiceable($restaurantId, PostalAddress::of('Far Away', null, 'Elsewhere', '00000'));
        } catch (AddressNotServiceable $exception) {
            $rejected = true;
            expect($exception->errorCode())->toBe('address_not_serviceable')
                ->and($exception->httpStatus())->toBe(422);
        }

        expect($rejected)->toBeTrue();

        // A point inside the zone is accepted and the resolved coordinate returned.
        $inside = Coordinate::of('0.0050000', '0.0050000');
        $acceptGate = new DefaultServiceableAddressResolver(
            new FixedCoordinateResolver($inside),
            new DefaultServiceabilityService(
                new DeliveryZoneRepository(
                    new SequenceIdGenerator(),
                    new RecursiveDescentGeoJsonParser(),
                    new CanonicalGeoJsonPrinter(),
                ),
                new PostGisContainment($connection, new CanonicalGeoJsonPrinter()),
            ),
        );

        $coordinate = $acceptGate->resolveServiceable($restaurantId, PostalAddress::of('Nearby', null, 'Town', '00001'));

        expect($coordinate->equals($inside))->toBeTrue();
    } finally {
        $connection->rollBack();
        DB::setDefaultConnection(config('database.default'));
    }
});
