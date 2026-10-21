<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->integer('tax_rate_bp')->default(0); // basis points
            $table->integer('base_delivery_fee')->default(0);
            $table->decimal('rating', 2, 1)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_status_check CHECK (status IN ('draft','pending_review','published','suspended'))");

        Schema::create('restaurant_cuisine_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('tag', 64);
            $table->unique(['restaurant_id', 'tag']);
        });

        Schema::create('restaurant_branches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name');
            $table->string('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // Menu_Category: name and display order, archived rather than deleted
        // (Requirements 11.1, 11.6, 34). The display-order value is bounded to
        // [0, 9999] (Requirement 11.9); the composite index backs the ordered
        // Menu read model's two-key sort (Requirement 4.1).
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name');
            $table->integer('display_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['restaurant_id', 'display_order', 'name']);
        });
        DB::statement('ALTER TABLE menu_categories ADD CONSTRAINT menu_categories_display_order_check CHECK (display_order BETWEEN 0 AND 9999)');

        // Menu_Item: a Price in minor units bounded to [1, 99999999]
        // (Requirement 11.3), an availability flag, and archive-on-delete
        // (Requirement 11.6). `category_id` keeps the established column name the
        // rest of the codebase already reads.
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('price_minor');
            $table->boolean('is_available')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['category_id', 'display_order', 'name']);
            $table->index('is_available');
        });
        DB::statement('ALTER TABLE menu_items ADD CONSTRAINT menu_items_price_minor_check CHECK (price_minor BETWEEN 1 AND 99999999)');
        DB::statement('ALTER TABLE menu_items ADD CONSTRAINT menu_items_display_order_check CHECK (display_order BETWEEN 0 AND 9999)');

        // Product_Variant: a Price of at least 1 minor unit (Requirement 34.1), an
        // availability flag, and a display-order value. `product_variants.id` is
        // referenced by order_lines, so the table and its key are preserved.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_minor');
            $table->boolean('is_available')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['menu_item_id', 'display_order', 'name']);
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_minor_check CHECK (price_minor >= 1)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_display_order_check CHECK (display_order BETWEEN 0 AND 9999)');

        // Modifier_Group: selection counts bounded to the documented ranges with
        // min <= max (Requirements 11.4, 34.3).
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name');
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->default(1);
            $table->integer('display_order')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['menu_item_id', 'display_order', 'name']);
        });
        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT modifier_groups_min_selections_check CHECK (min_selections BETWEEN 0 AND 50)');
        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT modifier_groups_max_selections_check CHECK (max_selections BETWEEN 1 AND 50)');
        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT modifier_groups_selection_order_check CHECK (min_selections <= max_selections)');
        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT modifier_groups_display_order_check CHECK (display_order BETWEEN 0 AND 9999)');

        // Modifier_Option: a signed Price_Delta in minor units bounded to
        // [-99999999, 99999999] (Requirements 11.2, 4.2). Named `modifier_options`
        // as the design's data model and the CatalogBoundary consumers expect.
        Schema::create('modifier_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_delta_minor')->default(0);
            $table->integer('display_order')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['modifier_group_id', 'display_order', 'name']);
        });
        DB::statement('ALTER TABLE modifier_options ADD CONSTRAINT modifier_options_price_delta_minor_check CHECK (price_delta_minor BETWEEN -99999999 AND 99999999)');
        DB::statement('ALTER TABLE modifier_options ADD CONSTRAINT modifier_options_display_order_check CHECK (display_order BETWEEN 0 AND 9999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('restaurant_branches');
        Schema::dropIfExists('restaurant_cuisine_tags');
        Schema::dropIfExists('restaurants');
    }
};
