<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes `delivery_addresses` to the columns Requirement 31.7–31.11 and the
 * design's Account data model require (task 4.6).
 *
 * The task 4.1 migration created `delivery_addresses` with a placeholder shape
 * (`address_line_1`, `city`, a plain `is_archived` boolean, and a non-partial
 * `UNIQUE(user_id, is_default)`), which cannot carry a Delivery_Address as
 * Requirement 31.7 defines it — a `label`, a first and optional second address
 * line, a `locality`, a `postal_code`, and an ISO 3166-1 alpha-2 `country_code`,
 * all contact detail stored encrypted at rest (Requirement 28.6) — nor enforce
 * the single-default invariant correctly. A non-partial `UNIQUE(user_id,
 * is_default)` admits at most one non-default row per Customer, the opposite of
 * what Requirement 31.9 needs.
 *
 * The table holds no production rows at this point in the build, so the forward
 * migration recreates it rather than patching column by column. The result:
 *
 *  - the free-form address parts live in `*_encrypted` text columns written
 *    through Laravel's `encrypted` cast, so PostgreSQL never stores the
 *    plaintext (Requirement 28.6, design Property 65);
 *  - archival is a nullable `archived_at` timestamp rather than a boolean, so an
 *    archived row keeps the instant it was archived (Requirement 31.10);
 *  - a partial unique index enforces one default per Customer among non-archived
 *    rows at the database level, independent of the one-transaction service rule
 *    (Requirements 31.9, 40.3, design Property 69);
 *  - the covering index `(user_id, archived_at, label, id)` serves the ordered
 *    list read of Requirement 31.11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delivery_addresses');

        Schema::create('delivery_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // The Customer-chosen label, 1–50 characters (Requirement 31.7). Not
            // contact detail, so stored in the clear and usable as a sort key.
            $table->string('label', 50);

            // Contact detail encrypted at rest (Requirement 28.6). Ciphertext is
            // longer and variable, so each is a text column.
            $table->text('line1_encrypted');
            $table->text('line2_encrypted')->nullable();
            $table->text('locality_encrypted');
            $table->text('postal_code_encrypted');

            // ISO 3166-1 alpha-2, two uppercase letters (Requirement 31.7). Not
            // contact detail; stored in the clear.
            $table->char('country_code', 2);

            // The coordinate the Zone module resolved, fixed-precision at 7
            // fractional digits to match the Coordinate value object
            // (Requirement 40.7).
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->boolean('is_default')->default(false);

            // Null until archived; the instant of archival once deleted
            // (Requirement 31.10).
            $table->timestampTz('archived_at')->nullable();

            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // Coordinate ranges enforced at the database level so a bypass of the
        // application still cannot store an out-of-range pair
        // (Requirement 40.4, design Property 69).
        DB::statement(
            'ALTER TABLE delivery_addresses ADD CONSTRAINT delivery_addresses_latitude_range_check '
            .'CHECK (latitude BETWEEN -90 AND 90)'
        );
        DB::statement(
            'ALTER TABLE delivery_addresses ADD CONSTRAINT delivery_addresses_longitude_range_check '
            .'CHECK (longitude BETWEEN -180 AND 180)'
        );

        // At most one default per Customer among non-archived rows
        // (Requirements 31.9, 40.3). A partial index is the only shape that both
        // permits many non-default rows and forbids a second default, which a
        // plain UNIQUE(user_id, is_default) cannot express.
        DB::statement(
            'CREATE UNIQUE INDEX delivery_addresses_one_default_per_user '
            .'ON delivery_addresses (user_id) WHERE is_default AND archived_at IS NULL'
        );

        // Covers the ordered non-archived list read of Requirement 31.11
        // (default first is applied in SQL; the remainder sorts by label then id).
        DB::statement(
            'CREATE INDEX delivery_addresses_user_listing '
            .'ON delivery_addresses (user_id, archived_at, label, id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_addresses');

        // Restore the task 4.1 placeholder shape so the migration is reversible.
        Schema::create('delivery_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('address_line_1');
            $table->text('address_line_1_encrypted')->nullable();
            $table->text('address_line_2')->nullable();
            $table->text('address_line_2_encrypted')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['user_id', 'is_default']);
            $table->index('user_id');
        });
    }
};
