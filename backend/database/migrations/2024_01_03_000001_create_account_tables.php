<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->ulid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

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

            // Partial unique index: default address per user (only non-archived)
            $table->unique(['user_id', 'is_default']);

            // Indexes for queries
            $table->index('user_id');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 64); // e.g. 'order_status', 'delivery_update'
            $table->string('channel', 32); // e.g. 'email', 'in_app', 'sms'
            $table->boolean('enabled')->default(true);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            // Unique per user/category/channel
            $table->unique(['user_id', 'category', 'channel']);
            $table->index('user_id');
        });

        Schema::create('email_change_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('new_email');
            $table->string('new_email_lower');
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            // The token itself is the lookup key on confirmation (design: UNIQUE(token_hash)).
            $table->unique('token_hash');
            // Finding a Customer's live, unused tokens to invalidate on reissue
            // and to confirm against (design: index (user_id, used_at)).
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_tokens');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('delivery_addresses');
        Schema::dropIfExists('user_profiles');
    }
};
