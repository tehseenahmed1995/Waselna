<?php

declare(strict_types=1);

// Feature: waselna, task 7.4: database-level overlap exclusion on the hours tables.

/*
 * Integration tests for the Postgres-specific guarantees of the task 7.4
 * migration (Requirements 11.7, 32.5, 40.6): the `opening_hours` / `branch_hours`
 * tables reject, at the database level, any two intervals of the same owner and
 * day of week that overlap under the half-open reading, and accept intervals
 * that merely touch.
 *
 * The service already rejects an overlapping submission with `invalid_hours`
 * before any write (covered portably in the Feature suite); this suite checks
 * the independent database bar — the GIST `EXCLUDE` constraint built on the
 * `btree_gist` extension — because it is Postgres-only and cannot run on the
 * SQLite database the Feature and Property suites use.
 *
 * The constraint depends on `btree_gist` and the `int4range` overlap operator,
 * so the test binds to the `pgsql` connection against the running PostGIS
 * container. When no such connection is reachable the test is skipped with a
 * message naming the dependency rather than reported as a failure, matching the
 * Zone integration suite's approach (Requirement 23.5).
 *
 * To avoid disturbing the shared database's real schema, the test builds its own
 * throwaway table carrying exactly the DDL the migration applies to
 * `opening_hours`, exercises the constraint against it, and drops it afterwards.
 */

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Postgres connection against the running container, or null when none is
 * reachable. Mirrors the Zone integration suite's resolver.
 */
function hoursPostgresConnectionOrNull(): ?Connection
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
            'database.connections.pgsql_hours_integration' => [
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

        DB::purge('pgsql_hours_integration');

        try {
            $pending = DB::connection('pgsql_hours_integration');
            $pending->select('SELECT 1');
            $connection = $pending;

            return $connection;
        } catch (Throwable) {
            DB::purge('pgsql_hours_integration');

            continue;
        }
    }

    return null;
}

/**
 * Builds a throwaway table carrying the exact overlap-exclusion DDL the task 7.4
 * migration applies to `opening_hours`, and returns its name.
 */
function makeHoursExclusionTable(Connection $db): string
{
    $table = 'it_hours_'.Str::lower(Str::random(12));

    $db->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    $db->statement(sprintf(
        'CREATE TABLE %s (id varchar(26) PRIMARY KEY, restaurant_id varchar(26) NOT NULL, day_of_week varchar(9) NOT NULL, start_minute integer NOT NULL, end_minute integer NOT NULL)',
        $table,
    ));
    $db->statement(sprintf(
        'ALTER TABLE %s ADD CONSTRAINT %s_minute_order_check CHECK (start_minute < end_minute)',
        $table,
        $table,
    ));
    $db->statement(sprintf(
        "ALTER TABLE %s ADD CONSTRAINT %s_no_overlap EXCLUDE USING gist (restaurant_id WITH =, day_of_week WITH =, int4range(start_minute, end_minute, '[)') WITH &&)",
        $table,
        $table,
    ));

    return $table;
}

function insertHoursRow(Connection $db, string $table, string $owner, string $day, int $start, int $end): void
{
    $db->table($table)->insert([
        'id' => (string) Str::ulid(),
        'restaurant_id' => $owner,
        'day_of_week' => $day,
        'start_minute' => $start,
        'end_minute' => $end,
    ]);
}

beforeEach(function (): void {
    $connection = hoursPostgresConnectionOrNull();

    if ($connection === null) {
        $this->markTestSkipped('No PostGIS/Postgres connection reachable; the hours EXCLUDE constraint needs Postgres with btree_gist.');
    }

    $this->db = $connection;
    $this->table = makeHoursExclusionTable($connection);
});

afterEach(function (): void {
    if (isset($this->db, $this->table)) {
        $this->db->statement(sprintf('DROP TABLE IF EXISTS %s', $this->table));
    }
});

it('rejects two overlapping intervals of the same owner and day', function (): void {
    $owner = (string) Str::ulid();

    insertHoursRow($this->db, $this->table, $owner, 'monday', 540, 720);

    expect(fn () => insertHoursRow($this->db, $this->table, $owner, 'monday', 700, 900))
        ->toThrow(QueryException::class);
});

it('accepts touching intervals because the overlap operator is half-open', function (): void {
    $owner = (string) Str::ulid();

    insertHoursRow($this->db, $this->table, $owner, 'monday', 540, 720);
    insertHoursRow($this->db, $this->table, $owner, 'monday', 720, 900);

    expect($this->db->table($this->table)->where('restaurant_id', $owner)->count())->toBe(2);
});

it('accepts the same interval on different days and for different owners', function (): void {
    $ownerA = (string) Str::ulid();
    $ownerB = (string) Str::ulid();

    insertHoursRow($this->db, $this->table, $ownerA, 'monday', 540, 720);
    // Same interval, different day — no overlap.
    insertHoursRow($this->db, $this->table, $ownerA, 'tuesday', 540, 720);
    // Same interval and day, different owner — no overlap.
    insertHoursRow($this->db, $this->table, $ownerB, 'monday', 540, 720);

    expect($this->db->table($this->table)->count())->toBe(3);
});
