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
        // The Zone module stores a PostGIS geometry column; the extension must
        // exist before the column is declared. The postgis/postgis image ships
        // the extension but does not enable it per-database automatically.
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        // Rider module
        Schema::create('courier_profiles', function (Blueprint $table): void {
            $table->ulid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('phone')->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->string('vehicle_type', 32)->nullable(); // 'bicycle', 'motorcycle', 'car'
            $table->string('rider_status', 32)->default('offline'); // online, offline, on_delivery, on_break, suspended
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE courier_profiles ADD CONSTRAINT courier_profiles_vehicle_type_check CHECK (vehicle_type IN ('bicycle','motorcycle','car'))");
        DB::statement("ALTER TABLE courier_profiles ADD CONSTRAINT courier_profiles_rider_status_check CHECK (rider_status IN ('online','offline','on_delivery','on_break','suspended'))");

        // Tracking module
        Schema::create('tracking_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('delivery_job_id')->constrained('delivery_jobs')->cascadeOnDelete();
            $table->foreignUlid('courier_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('delivery_job_id');
            $table->index('courier_id');
        });

        Schema::create('courier_current_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('courier_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestampTz('updated_at')->nullable();

            // One current location per courier
            $table->unique('courier_id');
            $table->index('courier_id');
        });

        Schema::create('courier_location_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('courier_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestampTz('created_at')->nullable();

            $table->index('courier_id');
            $table->index('created_at');
        });

        // Notification module
        Schema::create('notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 64); // 'order_confirmed', 'order_ready', etc.
            $table->string('channel', 32); // 'email', 'in_app', 'sms'
            $table->string('status', 32)->default('pending'); // pending, sent, failed, read
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable(); // context data, e.g. order_id
            $table->text('error_message')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('email','in_app','sms'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('pending','sent','failed','read'))");

        // Review module
        Schema::create('reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->integer('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            // One review per delivered order
            $table->unique('order_id');
            $table->index('restaurant_id');
            $table->index('customer_id');
            $table->index('created_at');
        });

        Schema::create('restaurant_rating_aggregates', function (Blueprint $table): void {
            $table->ulid('restaurant_id')->primary();
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->integer('total_reviews')->default(0);
            $table->decimal('average_rating', 2, 1)->nullable();
            $table->timestampTz('last_updated_at')->nullable();
        });

        // Zone module
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('restaurant_branch_id')->constrained('restaurant_branches')->cascadeOnDelete();
            $table->json('geojson'); // GeoJSON MultiPolygon or Polygon
            $table->geometry('geometry', 'Polygon', 4326)->nullable(); // PostGIS geometry
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('restaurant_branch_id');
        });

        // Pricing module
        Schema::create('promotions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 64)->unique();
            $table->integer('discount_amount_minor');
            $table->string('discount_currency', 3);
            $table->integer('max_uses')->nullable(); // null = unlimited
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
            $table->string('condition_type', 64); // 'minimum_order_value', 'restaurant_tag', etc.
            $table->json('condition_value');
            $table->timestampTz('created_at')->nullable();

            $table->index('promotion_id');
        });

        // Admin module - Audit Trail
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 64); // 'Restaurant', 'Order', etc.
            $table->string('entity_id', 36);
            $table->string('action', 64); // 'create', 'update', 'delete', 'suspend'
            $table->json('changes')->nullable(); // {field: {old, new}, ...}
            $table->json('metadata')->nullable(); // extra context
            $table->timestampTz('created_at')->nullable();

            $table->index('admin_user_id');
            $table->index('entity_type');
            $table->index('entity_id');
            $table->index('created_at');
        });

        // Platform configuration
        Schema::create('platform_configuration', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->integer('service_fee_percentage_min_bp')->default(500); // 5% in basis points
            $table->integer('service_fee_percentage_max_bp')->default(1500); // 15%
            $table->integer('delivery_fee_min_minor')->default(0);
            $table->integer('delivery_fee_max_minor')->default(5000); // $50
            $table->integer('offer_radius_km_default')->default(5);
            $table->integer('offer_radius_km_max')->default(20);
            $table->json('rejection_reasons')->nullable(); // reasons for rejecting orders
            $table->json('notification_defaults')->nullable(); // default notification preferences
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_configuration');
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('restaurant_rating_aggregates');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('courier_location_history');
        Schema::dropIfExists('courier_current_locations');
        Schema::dropIfExists('tracking_sessions');
        Schema::dropIfExists('courier_profiles');
    }
};
