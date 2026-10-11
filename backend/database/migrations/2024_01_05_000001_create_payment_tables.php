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
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->integer('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('pending'); // pending, authorized, captured, declined, failed, refunded
            $table->string('gateway', 64)->default('simulated'); // which payment gateway processed this
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('order_id');
            $table->index('status');
        });
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check CHECK (status IN ('pending','authorized','captured','declined','failed','refunded'))");

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->string('event_type', 64); // 'authorization_requested', 'authorization_succeeded', 'capture_requested', etc.
            $table->json('event_data')->nullable();
            $table->string('gateway_event_id')->nullable(); // idempotency across gateway calls
            $table->timestampTz('created_at')->nullable();

            $table->index('payment_intent_id');
            $table->index('gateway_event_id');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->integer('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('pending'); // pending, succeeded, failed
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('payment_intent_id');
            $table->index('status');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending','succeeded','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_intents');
    }
};
