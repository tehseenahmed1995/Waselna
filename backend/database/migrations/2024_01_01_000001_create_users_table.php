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
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('email', 254);
            $table->string('email_lower', 254)->unique();
            $table->string('password_hash');
            $table->string('display_name', 100);
            $table->string('role', 32);
            $table->string('account_status', 16)->default('active');
            $table->ulid('restaurant_id')->nullable();
            $table->ulid('branch_id')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer','restaurant_operator','restaurant_staff','courier','administrator'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_status_check CHECK (account_status IN ('active','suspended'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
