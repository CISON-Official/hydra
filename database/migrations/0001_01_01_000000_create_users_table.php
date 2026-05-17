<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary(); // Swapped to UUID Primary Key
            $table->string('email', 255)->unique()->index();
            $table->string('name', 255);
            $table->string('role', 50); // issuer, holder, verifier, admin
            $table->string('api_key_hash', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_login')->nullable();
            
            // Kept for basic Laravel compatibility (optional but harmless)
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });

        // PostgreSQL Raw Constraint: Enforce single active admin
        DB::statement("
            CREATE UNIQUE INDEX uq_single_active_admin 
            ON users (role, is_active) 
            WHERE role = 'admin' AND is_active = true
        ");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
