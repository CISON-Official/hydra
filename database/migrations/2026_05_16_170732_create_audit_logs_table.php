<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('certificate_id')->nullable()->index();
            $table->string('action', 50);
            $table->string('actor_role', 50);
            $table->string('actor_id', 255)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('success')->default(true);
            $table->jsonb('details')->nullable();

            // Timestamp with timezone, defaulting to current time, indexed
            $table->timestampTz('timestamp')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
