<?php

declare(strict_types=1);

// Feature: waselna, Property 18: Containment is boundary-inclusive and implementation-agnostic

/*
 * *For any* Delivery_Zone polygon with any number of interior rings and any
 * probe coordinate pair — inside, outside, exactly on the exterior ring, or
 * exactly on an interior ring — the PostGIS containment query and the in-memory
 * containment evaluator return the same decision, boundary points counting as
 * inside.
 *
 * **Validates: Requirements 5.8, 5.9, 32.11**
 *
 * This is one of the two model-based properties of the Testing Strategy: the
 * optimized production path (PostGIS `ST_Covers`, whose closed semantics give
 * the boundary-inclusive rule of Requirement 5.8) is pinned to the obvious
 * in-memory twin (RayCastContainment) so the two can never silently diverge.
 * Both evaluators are handed the *same* PolygonGeometry and the *same* probe
 * Coordinate, and the property asserts a single equality:
 *
 *     PostGisContainment::contains(poly, p) === RayCastContainment::contains(poly, p)
 *
 * for every generated (polygon, point) pair, with ≥100 generated inputs.
 *
 * The subject depends on PostGIS, so it lives in the Integration suite rather
 * than the Property suite: `ST_GeomFromGeoJSON`, `ST_MakePoint`, and
 * `ST_Covers` cannot run on the SQLite database the other suites use. The test
 * therefore binds to the `pgsql` connection against the running PostGIS
 * container. When no PostGIS connection is reachable the test is skipped with a
 * message naming the dependency rather than reported as a failure, so the
 * suite stays honest about what it did and did not exercise (Requirement 23.5
 * keeps the integration set separately selectable for exactly this reason).
 *
 * Geometry generation strategy. PostGIS `ST_Covers` is only meaningful on a
 * valid polygon, and the stored-geometry table enforces `ST_IsValid`
 * (migration 2024_01_08). To compare the two evaluators on well-defined
 * geometries the generator emits axis-aligned rectangles: an exterior
 * rectangle, optionally with a smaller rectangular hole strictly inside it.
 * These are valid, non-self-intersecting rings by construction, which lets the
 * property isolate what it is actually about — agreement on the containment
 * decision, especially the boundary-inclusive rule — rather than agreement on
 * how each engine treats a degenerate polygon.
 *
 * Probe-point strategy. For each generated polygon the test derives one point
 * from every category Property 18 names: strictly interior, strictly exterior,
 * exactly on an exterior-ring edge or vertex, and exactly on an interior-ring
 * edge or vertex when a hole exists. The on-boundary points are the crux: a
 * point on any ring boundary must be reported inside by both engines
 * (Requirement 5.8), and a point on an interior-ring boundary must be inside
 * even though it also bounds a hole. Points are placed on exact rational
 * coordinates at 7 fractional digits so the value each engine sees is identical
 * and the on-edge placement is exact rather than approximate.
 */

use App\Modules\Zone\Domain\CanonicalGeoJsonPrinter;
use App\Modules\Zone\Domain\PolygonGeometry;
use App\Modules\Zone\Domain\RayCastContainment;
use App\Modules\Zone\Services\PostGisContainment;
use App\Shared\Domain\Coordinate;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

uses(TestTrait::class);

/**
 * A PostGIS-backed database connection, or null when none is reachable.
 *
 * The suite's phpunit.xml forces SQLite, which cannot evaluate `ST_Covers`, so
 * this resolves the `pgsql` connection explicitly, pointed at the running
 * PostGIS container. Host and credentials come from the environment when set
 * and otherwise fall back to the Local_Dev_Environment defaults; a probe query
 * confirms PostGIS answers before the connection is used.
 */
function postgisConnectionOrNull(): ?Connection
{
    static $resolved = false;
    static $connection = null;

    if ($resolved) {
        return $connection;
    }

    $resolved = true;

    $candidates = [
        // Inside the backend container the service is reachable by name.
        ['host' => env('DB_HOST', 'postgres'), 'port' => (int) env('DB_PORT', 5432)],
        // From the host the published port maps the same container.
        ['host' => '127.0.0.1', 'port' => 5432],
    ];

    foreach ($candidates as $candidate) {
        config([
            'database.connections.pgsql_integration' => [
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

        DB::purge('pgsql_integration');

        try {
            $pending = DB::connection('pgsql_integration');
            // Exercise the exact PostGIS functions the production query uses so
            // a reachable-but-non-spatial database is also rejected.
            $pending->select(
                'SELECT ST_Covers(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ST_SetSRID(ST_MakePoint(0, 0), 4326)) AS ok',
                ['{"type":"Polygon","coordinates":[[[0,0],[0,1],[1,1],[1,0],[0,0]]]}'],
            );

            $connection = $pending;

            return $connection;
        } catch (\Throwable) {
            DB::purge('pgsql_integration');

            continue;
        }
    }

    return null;
}

/**
 * A decimal string at 7 fractional digits for an integer count of 1e-7 units,
 * matching the precision Coordinate normalises every value to. Working in these
 * integer "ticks" keeps generated edges, vertices, and midpoints exact so a
 * point placed on a boundary is on the boundary for both engines.
 */
function tickDegrees(int $ticks): string
{
    $negative = $ticks < 0;
    $magnitude = abs($ticks);

    $whole = intdiv($magnitude, 10_000_000);
    $fraction = $magnitude % 10_000_000;

    $value = $whole.'.'.str_pad((string) $fraction, 7, '0', STR_PAD_LEFT);

    return $negative ? '-'.$value : $value;
}

/**
 * A closed rectangular ring from its lower-left and upper-right corners,
 * expressed in integer ticks, wound counter-clockwise (exterior orientation).
 * Corners are the four vertices with the first repeated as the last.
 *
 * @return list<Coordinate>
 */
function rectangleRing(int $lonTicks, int $latTicks, int $widthTicks, int $heightTicks): array
{
    $x0 = tickDegrees($lonTicks);
    $y0 = tickDegrees($latTicks);
    $x1 = tickDegrees($lonTicks + $widthTicks);
    $y1 = tickDegrees($latTicks + $heightTicks);

    return [
        new Coordinate($y0, $x0),
        new Coordinate($y0, $x1),
        new Coordinate($y1, $x1),
        new Coordinate($y1, $x0),
        new Coordinate($y0, $x0),
    ];
}

/**
 * A generated agreement case: a valid rectangular polygon (optionally holed)
 * and one probe point drawn from a category Property 18 names.
 *
 * The exterior rectangle sits near the origin with modest, bounded dimensions
 * so every coordinate stays well inside the WGS 84 ranges. When a hole is
 * requested it is a smaller rectangle inset strictly inside the exterior, so
 * the geometry is valid and the interior ring has its own boundary to probe.
 */
function agreementCases(): ErisGenerator
{
    return Generators::map(
        /**
         * @param  array{0:int,1:int,2:int,3:int,4:bool,5:int}  $parts
         * @return array{polygon: PolygonGeometry, point: Coordinate, label: string}
         */
        static function (array $parts): array {
            [$lonTicks, $latTicks, $widthTicks, $heightTicks, $withHole, $category] = $parts;

            // Exterior rectangle, winding counter-clockwise.
            $rings = [rectangleRing($lonTicks, $latTicks, $widthTicks, $heightTicks)];

            // A hole inset by a quarter of each dimension, so it sits strictly
            // inside the exterior with room on every side.
            $holeLon = $lonTicks + intdiv($widthTicks, 4);
            $holeLat = $latTicks + intdiv($heightTicks, 4);
            $holeWidth = intdiv($widthTicks, 2);
            $holeHeight = intdiv($heightTicks, 2);

            $hasHole = $withHole && $holeWidth >= 2 && $holeHeight >= 2;

            if ($hasHole) {
                $rings[] = rectangleRing($holeLon, $holeLat, $holeWidth, $holeHeight);
            }

            $polygon = new PolygonGeometry($rings);

            // Centre of the exterior rectangle.
            $centreLon = $lonTicks + intdiv($widthTicks, 2);
            $centreLat = $latTicks + intdiv($heightTicks, 2);

            // Choose a probe point by category, falling back when a category is
            // unavailable (e.g. an interior-boundary probe without a hole).
            [$point, $label] = match ($category % 5) {
                // Strictly interior: a point between the exterior edge and the
                // hole, away from both boundaries.
                0 => [
                    new Coordinate(tickDegrees($centreLat), tickDegrees($lonTicks + intdiv($widthTicks, 8))),
                    'interior',
                ],
                // Strictly exterior: a point to the left of the exterior rectangle.
                1 => [
                    new Coordinate(tickDegrees($centreLat), tickDegrees($lonTicks - 5)),
                    'exterior',
                ],
                // Exactly on an exterior edge: the midpoint of the bottom edge.
                2 => [
                    new Coordinate(tickDegrees($latTicks), tickDegrees($centreLon)),
                    'on-exterior-edge',
                ],
                // Exactly on an exterior vertex: the lower-left corner.
                3 => [
                    new Coordinate(tickDegrees($latTicks), tickDegrees($lonTicks)),
                    'on-exterior-vertex',
                ],
                // Exactly on an interior-ring edge when a hole exists; otherwise
                // the centre, which is strictly interior.
                default => $hasHole
                    ? [
                        new Coordinate(
                            tickDegrees($holeLat),
                            tickDegrees($holeLon + intdiv($holeWidth, 2)),
                        ),
                        'on-interior-edge',
                    ]
                    : [
                        new Coordinate(tickDegrees($centreLat), tickDegrees($centreLon)),
                        'interior-centre',
                    ],
            };

            return ['polygon' => $polygon, 'point' => $point, 'label' => $label];
        },
        Generators::tuple(
            // Exterior lower-left longitude/latitude in ticks, kept small so the
            // whole polygon stays near the origin and inside the ranges.
            Generators::choose(-20_000_000, 20_000_000),
            Generators::choose(-20_000_000, 20_000_000),
            // Width and height in ticks: at least 8 so quarter/eighth insets are
            // non-degenerate, bounded so the polygon stays modest.
            Generators::choose(8, 5_000_000),
            Generators::choose(8, 5_000_000),
            Generators::bool(),
            Generators::choose(0, 4),
        ),
    );
}

it('agrees between PostGIS and the in-memory ray-cast evaluator for every polygon and probe point', function (): void {
    $connection = postgisConnectionOrNull();

    if ($connection === null) {
        $this->markTestSkipped(
            'Property 18 compares PostGIS ST_Covers against the in-memory evaluator and '
            .'requires the postgis/postgis PostgreSQL container (not SQLite). No reachable '
            .'PostGIS connection was found via the pgsql_integration connection.',
        );
    }

    $printer = new CanonicalGeoJsonPrinter();
    $postgis = new PostGisContainment($connection, $printer);
    $rayCast = new RayCastContainment();

    $this->limitTo(100)->forAll(
        agreementCases(),
    )->then(function (array $case) use ($postgis, $rayCast): void {
        /** @var PolygonGeometry $polygon */
        $polygon = $case['polygon'];
        /** @var Coordinate $point */
        $point = $case['point'];

        $postgisDecision = $postgis->contains($polygon, $point);
        $rayCastDecision = $rayCast->contains($polygon, $point);

        // The one equality this property is about: the optimized and obvious
        // implementations agree on every point, boundary points included.
        expect($postgisDecision)->toBe(
            $rayCastDecision,
            sprintf(
                'PostGIS and ray-cast disagree for a %s point at [%s, %s]: PostGIS=%s, ray-cast=%s.',
                $case['label'],
                $point->longitude,
                $point->latitude,
                $postgisDecision ? 'inside' : 'outside',
                $rayCastDecision ? 'inside' : 'outside',
            ),
        );

        // Boundary points must be inside under both engines (Requirement 5.8);
        // asserting it here makes the boundary-inclusive rule explicit rather
        // than leaving it implied by the agreement check alone.
        if (str_starts_with($case['label'], 'on-')) {
            expect($rayCastDecision)->toBeTrue()
                ->and($postgisDecision)->toBeTrue();
        }
    });
});
