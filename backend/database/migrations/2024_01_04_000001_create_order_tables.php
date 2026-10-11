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
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignUlid('restaurant_branch_id')->constrained('restaurant_branches')->cascadeOnDelete();
            $table->string('order_number', 32)->unique(); // e.g. WAS-2024-001234
            $table->string('status', 32)->default('pending_payment'); // pending_payment, confirmed, ready_for_pickup, assigned_to_rider, picked_up, out_for_delivery, delivered, cancelled, failed
            $table->integer('line_subtotal_minor'); // total of line items in minor units
            $table->integer('delivery_fee_minor'); // clamped delivery fee
            $table->integer('service_fee_minor'); // percentage-based fee
            $table->integer('discount_amount_minor')->default(0); // applied promotion discount
            $table->integer('tax_amount_minor'); // tax on subtotal
            $table->integer('total_amount_minor'); // final order total
            $table->string('currency', 3)->default('USD');
            $table->json('delivery_address_snapshot'); // frozen address at order time
            $table->text('notes')->nullable();
            $table->string('promotion_code', 64)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            // Indexes
            $table->index('customer_id');
            $table->index('restaurant_id');
            $table->index('status');
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment','confirmed','preparing','ready_for_pickup','assigned_to_rider','picked_up','out_for_delivery','delivered','cancelled','rejected','failed','payment_failed'))");

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUlid('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('quantity');
            $table->integer('unit_price_minor'); // frozen menu item price
            $table->integer('subtotal_minor'); // unit_price_minor * quantity
            $table->string('currency', 3);
            $table->json('menu_item_snapshot'); // frozen item details
            $table->json('variant_snapshot')->nullable(); // frozen variant details
            $table->timestampTz('created_at')->nullable();

            // Indexes
            $table->index('order_id');
            $table->index('menu_item_id');
        });

        Schema::create('order_line_modifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->string('modifier_group_name', 128);
            $table->string('modifier_name', 128);
            $table->integer('price_minor');
            $table->string('currency', 3);

            $table->index('order_line_id');
        });

        Schema::create('order_timeline_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('new_status', 32); // the status after this transition
            $table->string('actor_role', 32)->nullable(); // 'customer', 'operator', 'staff', 'admin', 'rider' or null for system
            $table->string('actor_id', 36)->nullable(); // user or courier id
            $table->text('notes')->nullable(); // e.g. rejection reason
            $table->timestampTz('created_at')->nullable();

            // Append-only, ordered by created_at
            $table->index('order_id');
            $table->index('created_at');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 256);
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->setOnDelete('set null');
            $table->json('response_payload')->nullable();
            $table->timestampTz('created_at')->nullable();

            // Unique per customer and key to enforce idempotency
            $table->unique(['customer_id', 'idempotency_key']);
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('order_timeline_entries');
        Schema::dropIfExists('order_line_modifiers');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
