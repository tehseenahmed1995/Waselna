<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog hours schema (task 7.4; Requirements 11.7, 11.8, 32.5, 32.6, 40.6).
 *
 * Task 7.1 created the Catalog tables but, as the task 7.2 subagent flagged, did
 * not add the Opening_Hours / Branch_Hours tables the design's data model names,
 * nor the time zone each schedule is read against. This migration completes that
 * part of the data model so the hours management endpoints have somewhere to
 * write.
 *
 * Added here:
 *
 *  - `restaurants.time_zone` — the IANA time zone the Restaurant's Opening_Hours
 *    are expressed in (Requirement 32.5, glossary Opening_Hours). Defaulted to
 *    `UTC` for the rows task 7.1 already created.
 *  - `restaurant_branches.time_zone` — the IANA time zone the Restaurant_Branch's
 *    Branch_Hours are expressed in; nullable, because a Restaurant_Branch that
 *    defines no Branch_Hours falls back to the Restaurant's Opening_Hours and its
 *    time zone (glossary Branch_Hours, Requirement 32.7).
 *  - `opening_hours` — one row per Opening_Hours interval, owned by a Restaurant.
 *  - `branch_hours` — one row per Branch_Hours interval, owned by a
 *    Restaurant_Branch.
 *
 * Both interval tables carry exactly the storage checks the design's data model
 * table fixes (Requirements 11.7, 32.5):
 *
 *  - `day_of_week` is one of the seven lowercase day names;
 *  - `start_minute` and `end_minute` both lie in the closed range 0 to 1,440;
 *  - `start_minute` is strictly less than `end_minute`;
 *  - no two intervals of the same owner and the same day of week overlap, read
 *    half-open so touching intervals are allowed. The overlap bar is a GIST
 *    `EXCLUDE` constraint over the owner id, the day, and the half-open integer
 *    range `[start_minute, end_minute)`, which needs the `btree_gist` extension
 *    to combine the equality parts (owner, day) with the range-overlap part in a
 *    single GIST operator class.
 *
 * The EXCLUDE constraint is the database's own guarantee of the per-day overlap
 * rule, independent of and in addition to the service-level validation that
 * rejects an overlapping submission with `invalid_hours` before any write
 * (Requirement 32.6). It is Postgres-specific; the suite's SQLite service tests
 * exercise the service rules, and the Postgres-backed tests exercise this bar.
 */
return new class extends Migration
{
    /**
     * The seven permitted day-of-week values, shared by both interval tables'
     * check constraints.
     */
    private const DAY_OF_WEEK_VALUES = "'monday','tuesday','wednesday','thursday','friday','saturday','sunday'";

    public function up(): void
    {
        // Needed by the EXCLUDE constraints below to put the equality columns
        // (owner id, day_of_week) into the same GIST index as the int4range
        // overlap operator. The extension ships with the postgis/postgis image.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // The IANA time zone each schedule is read against (Requirement 32.5).
        // Existing rows from task 7.1 default to UTC; the column is not null so
        // every Restaurant always has a time zone to localise against.
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('time_zone', 64)->default('UTC')->after('currency');
        });

        // Nullable: a Restaurant_Branch with no Branch_Hours falls back to the
        // Restaurant's Opening_Hours and its time zone (Requirement 32.7).
        Schema::table('restaurant_branches', function (Blueprint $table): void {
            $table->string('time_zone', 64)->nullable()->after('longitude');
        });

        $this->createHoursTable('opening_hours', 'restaurant_id', 'restaurants');
        $this->createHoursTable('branch_hours', 'restaurant_branch_id', 'restaurant_branches');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hours');
        Schema::dropIfExists('opening_hours');

        Schema::table('restaurant_branches', function (Blueprint $table): void {
            $table->dropColumn('time_zone');
        });

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('time_zone');
        });
    }

    /**
     * Creates one of the two structurally identical interval tables, differing
     * only in the owner column and the table it references.
     */
    private function createHoursTable(string $table, string $ownerColumn, string $ownerTable): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($ownerColumn, $ownerTable): void {
            $blueprint->ulid('id')->primary();
            $blueprint->foreignUlid($ownerColumn)->constrained($ownerTable)->cascadeOnDelete();
            $blueprint->string('day_of_week', 9);
            $blueprint->integer('start_minute');
            $blueprint->integer('end_minute');

            // Reading an owner's whole schedule, and the per-owner-per-day
            // lookup the evaluator and the EXCLUDE constraint both key on.
            $blueprint->index([$ownerColumn, 'day_of_week']);
        });

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s_day_of_week_check CHECK (day_of_week IN (%s))',
            $table,
            $table,
            self::DAY_OF_WEEK_VALUES,
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s_minute_bounds_check CHECK (start_minute BETWEEN 0 AND 1440 AND end_minute BETWEEN 0 AND 1440)',
            $table,
            $table,
        ));

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s_minute_order_check CHECK (start_minute < end_minute)',
            $table,
            $table,
        ));

        // No two intervals of the same owner and day overlap, read half-open so
        // an interval ending where the next begins is allowed (Requirement 32.5).
        // int4range(start, end, '[)') is the half-open [start, end) span; the
        // `&&` operator is range overlap. btree_gist supplies the equality
        // operator classes for the owner id and the day of week.
        DB::statement(sprintf(
            "ALTER TABLE %s ADD CONSTRAINT %s_no_overlap EXCLUDE USING gist (%s WITH =, day_of_week WITH =, int4range(start_minute, end_minute, '[)') WITH &&)",
            $table,
            $table,
            $ownerColumn,
        ));
    }
};
