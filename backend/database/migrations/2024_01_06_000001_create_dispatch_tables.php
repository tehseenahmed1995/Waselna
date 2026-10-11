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
        Schema::create('delivery_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('assigned_courier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('unassigned'); // unassigned, offered, accepted, pickup, picked_up, in_transit, delivered, failed, cancelled
            $table->json('pickup_location'); // {latitude, longitude} from restaurant branch
            $table->json('dropoff_location'); // {latitude, longitude} from delivery address
            $table->integer('distance_meters')->nullable();
            $table->integer('estimated_minutes')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('order_id');
            $table->index('assigned_courier_id');
            $table->index('status');
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE delivery_jobs ADD CONSTRAINT delivery_jobs_status_check CHECK (status IN ('unassigned','offered','accepted','pickup','picked_up','in_transit','delivered','failed','cancelled'))");

        Schema::create('delivery_job_offers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('delivery_job_id')->constrained('delivery_jobs')->cascadeOnDelete();
            $table->foreignUlid('courier_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('offered'); // offered, accepted, declined, withdrawn, expired
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            // Partial unique: only one active offer per job (where status = offered)
            $table->unique(['delivery_job_id', 'courier_id']);

            $table->index('delivery_job_id');
            $table->index('courier_id');
            $table->index('status');
        });
        DB::statement("ALTER TABLE delivery_job_offers ADD CONSTRAINT delivery_job_offers_status_check CHECK (status IN ('offered','accepted','declined','withdrawn','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_job_offers');
        Schema::dropIfExists('delivery_jobs');
    }
};
