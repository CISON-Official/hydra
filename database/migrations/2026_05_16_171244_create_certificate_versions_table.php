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
        Schema::create('certificate_versions', function (Blueprint $table) {
            $table->uuid('certificate_id')->index();

            // Version specifications
            $table->integer('version_number');
            $table->string('file_hash', 64);
            $table->string('s3_key', 512);
            $table->integer('file_size_bytes');
            $table->uuid('updated_by');
            $table->string('update_reason', 500);

            // Timestamps
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Compound Index from __table_args__
            $table->index(['certificate_id', 'version_number'], 'idx_cert_version_cert');

            // Unique Constraint to prevent overlapping history versions
            $table->unique(['certificate_id', 'version_number'], 'uq_cert_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificate_versions');
    }
};
