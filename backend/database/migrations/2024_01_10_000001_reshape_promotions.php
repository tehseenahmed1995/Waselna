<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes `promotions` and `promotion_conditions` to the columns the design's
 * Pricing data model and Requirements 7.5–7.7 and 18.4 require (task 12.4).
 *
 * The task-1-era `2024_01_07_000001_create_remaining_tables` migration created
 * `promotions` with a placeholder shape (`discount_amount_minor`,
 * `discount_currency`, `max_uses`, `times_used`, `active_from`, `active_until`)
 * and `promotion_conditions` with `condition_type` / `condition_value`. The
 * design's authoritative shape is different: a Promotion carries a
 * `discount_kind` of `amount` or `percent`, a single `discount_value` whose
 * range depends on the kind (1..99,999,999 minor units for `amount`, 1..100
 * percent for `percent`, per Requirement 18.4), an optional `restaurant_id`
 * scoping it to one Restaurant, an `activates_at`/`expires_at` activation window
 * with `activates_at < expires_at`, an `is_active` flag, and a usage cap with a
 * running usage count. Codes are unique case-insensitively, which the design
 * records as `UNIQUE(lower(code))` and the single-code rule of Requirement 7.6
 * relies on.
 *
 * The tables hold no production rows at this point in the build, so the forward
 * migration recreates them rather than patching column by column, matching the
 * `2024_01_09_000001_reshape_delivery_addresses` precedent. The `promotions`
 * currency is dropped from a per-row column: a Promotion applies to a Cart whose
 * currency is fixed by its Restaurant, and an `amount` discount is clamped into
 * that Cart's currency by the Pricing_Engine, so the discount value is a bare
 * integer (minor units for `amount`, whole percent for `percent`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // promotion_conditions references promotions, so it is dropped first and
        // recreated after the parent.
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotions');

        Schema::create('promotions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The Customer-entered code. Compared case-insensitively, so the
            // stored form keeps the Administrator's casing and a case-folded
            // unique index (added below) enforces single-code uniqueness.
            $table->string('code', 64);

            // Optional scope to one Restaurant (design `restaurant_id?`). Null
            // means the Promotion applies to any Restaurant. No foreign key: the
            // Catalog module owns `restaurants` and Pricing must not couple to
            // its schema, so the scope is validated through the Catalog boundary
            // at creation, not by a cross-module constraint.
            $table->ulid('restaurant_id')->nullable();

            // 'amount' = fixed minor-unit discount, 'percent' = percentage of
            // Line_Subtotal + Delivery_Fee (design `discount_kind`).
            $table->string('discount_kind', 16);

            // Minor units when kind is 'amount'; whole percent (1..100) when kind
            // is 'percent' (Requirement 18.4).
            $table->integer('discount_value');

            // The activation window, both instants in UTC (Requirement 18.4). The
            // Promotion applies from activates_at (inclusive) until expires_at
            // (exclusive), evaluated against the injected Clock.
            $table->timestampTz('activates_at');
            $table->timestampTz('expires_at');

            // An Administrator may deactivate a Promotion without deleting it.
            $table->boolean('is_active')->default(true);

            // The overall redemption cap and the running count of redemptions.
            // Null max_uses means unlimited (Requirement 7.6 usage condition).
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);

            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('restaurant_id');
        });

        // Case-insensitive single-code uniqueness (design `UNIQUE(lower(code))`).
        DB::statement(
            'CREATE UNIQUE INDEX promotions_code_lower_unique ON promotions (lower(code))'
        );

        // The activation window is well formed (Requirement 18.4).
        DB::statement(
            'ALTER TABLE promotions ADD CONSTRAINT promotions_window_check '
            .'CHECK (activates_at < expires_at)'
        );

        // Discount kind is one of the two permitted values (design CHECK).
        DB::statement(
            'ALTER TABLE promotions ADD CONSTRAINT promotions_discount_kind_check '
            ."CHECK (discount_kind IN ('amount','percent'))"
        );

        // The value range per kind (Requirement 18.4): 1..99,999,999 minor units
        // for an amount, 1..100 percent for a percentage.
        DB::statement(
            'ALTER TABLE promotions ADD CONSTRAINT promotions_discount_value_check '
            .'CHECK ('
            ."(discount_kind = 'amount'  AND discount_value BETWEEN 1 AND 99999999) OR "
            ."(discount_kind = 'percent' AND discount_value BETWEEN 1 AND 100)"
            .')'
        );

        // The usage cap, when set, is positive and the running count never
        // negative.
        DB::statement(
            'ALTER TABLE promotions ADD CONSTRAINT promotions_max_uses_check '
            .'CHECK (max_uses IS NULL OR max_uses >= 1)'
        );
        DB::statement(
            'ALTER TABLE promotions ADD CONSTRAINT promotions_times_used_check '
            .'CHECK (times_used >= 0)'
        );

        Schema::create('promotion_conditions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('promotion_id')->constrained('promotions')->cascadeOnDelete();

            // The eligibility condition kind (design `kind`): one of
            // 'minimum_subtotal', 'restaurant', 'first_order_only'.
            $table->string('kind', 64);

            // The condition operand (design `operand`): a scalar whose meaning
            // depends on the kind — a minor-unit threshold, a Restaurant
            // identifier, or empty for a flag condition. Stored as JSON so a
            // future condition kind can carry a richer operand without a schema
            // change.
            $table->json('operand')->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index('promotion_id');
        });

        DB::statement(
            'ALTER TABLE promotion_conditions ADD CONSTRAINT promotion_conditions_kind_check '
            ."CHECK (kind IN ('minimum_subtotal','restaurant','first_order_only'))"
        );
    }

    public function down(): void
    {
        // Restore the task-1 placeholder shape so the migration is reversible.
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotions');

        Schema::create('promotions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 64)->unique();
            $table->integer('discount_amount_minor');
            $table->string('discount_currency', 3);
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('active_from')->nullable();
            $table->timestampTz('active_until')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('code');
            $table->index('is_active');
        });

        Schema::create('promotion_conditions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('condition_type', 64);
            $table->json('condition_value');
            $table->timestampTz('created_at')->nullable();

            $table->index('promotion_id');
        });
    }
};
