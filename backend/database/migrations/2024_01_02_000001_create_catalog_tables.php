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

        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name');
            $table->integer('display_order')->default(0);
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('price_minor')->default(0);
            $table->boolean('is_available')->default(true);
            $table->integer('display_order')->default(0);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_minor')->nullable(); // null means use item price
            $table->boolean('is_required')->default(false);
            $table->integer('display_order')->default(0);
            $table->string('currency', 3);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('menu_item_id');
        });

        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name');
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->default(1);
            $table->integer('display_order')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('menu_item_id');
        });

        Schema::create('modifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_minor')->default(0);
            $table->string('currency', 3);
            $table->integer('display_order')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('modifier_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('restaurant_branches');
        Schema::dropIfExists('restaurant_cuisine_tags');
        Schema::dropIfExists('restaurants');
    }
};
