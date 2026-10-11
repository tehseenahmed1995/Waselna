<?php

declare(strict_types=1);

/*
 * The health endpoint in both documented states.
 *
 * Requirement 27.1: HTTP 200 carrying the state of the PostgreSQL connection,
 * the Cache_Store connection, and the queue connection, within 1 second.
 * Requirement 27.2: HTTP 503 naming the unreachable dependency when a checked
 * dependency does not answer.
 *
 * The degraded cases point a dependency at an address nothing listens on rather
 * than replacing the probe, so what is exercised is the real driver failure the
 * endpoint has to survive.
 */

use App\Shared\Http\Health\DependencyStatus;
use App\Shared\Http\Health\HealthProbe;
use App\Shared\Http\Health\HealthReport;

use function Pest\Laravel\getJson;

/**
 * A Redis connection to a port nothing listens on.
 */
function unreachableRedis(): void
{
    config([
        'database.redis.unreachable' => [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 0,
        ],
    ]);
}

it('answers 200 with the state of every checked dependency within a second', function (): void {
    // The framework's own first-request cost — autoloading, route compilation,
    // opening the connections — is not what Requirement 27.1 budgets, so the
    // measured request is one against a warmed application, as every request an
    // operator's poll makes is.
    getJson('/api/v1/health')->assertOk();

    $started = microtime(true);

    $response = getJson('/api/v1/health');

    $elapsed = microtime(true) - $started;

    $response->assertOk()->assertExactJson([
        'data' => [
            'status' => HealthReport::HEALTHY,
            'dependencies' => [
                HealthProbe::POSTGRES => DependencyStatus::REACHABLE,
                HealthProbe::CACHE => DependencyStatus::REACHABLE,
                HealthProbe::QUEUE => DependencyStatus::REACHABLE,
            ],
            'unreachable' => [],
        ],
    ]);

    expect($elapsed)->toBeLessThan(1.0);
});

it('answers 503 and names the unreachable dependency', function (Closure $break, string $dependency): void {
    $break();

    getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('data.status', HealthReport::DEGRADED)
        ->assertJsonPath('data.dependencies.'.$dependency, DependencyStatus::UNREACHABLE)
        ->assertJsonPath('data.unreachable', [$dependency]);
})->with([
    'PostgreSQL' => [
        static function (): void {
            // A database file under a directory that does not exist: the driver
            // cannot open it, exactly as it cannot reach an absent server.
            config([
                'database.connections.unreachable' => [
                    'driver' => 'sqlite',
                    'database' => storage_path('framework/health-probe-absent/database.sqlite'),
                    'prefix' => '',
                    'foreign_key_constraints' => false,
                ],
                'database.default' => 'unreachable',
            ]);
        },
        HealthProbe::POSTGRES,
    ],
    'Cache_Store' => [
        static function (): void {
            unreachableRedis();

            config([
                'cache.stores.unreachable' => [
                    'driver' => 'redis',
                    'connection' => 'unreachable',
                    'lock_connection' => 'unreachable',
                ],
                'cache.default' => 'unreachable',
            ]);
        },
        HealthProbe::CACHE,
    ],
    'queue backend' => [
        static function (): void {
            unreachableRedis();

            config([
                'queue.connections.unreachable' => [
                    'driver' => 'redis',
                    'connection' => 'unreachable',
                    'queue' => 'default',
                    'retry_after' => 90,
                    'block_for' => null,
                    'after_commit' => false,
                ],
                'queue.default' => 'unreachable',
            ]);
        },
        HealthProbe::QUEUE,
    ],
]);
