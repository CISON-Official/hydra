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
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Core Relationships & Identifiers
            $table->uuid('issuer_id')->index();
            $table->string('holder_email', 255)->index();
            $table->string('holder_name', 255);
            $table->string('certificate_title', 500);

            // File tracking
            $table->integer('current_version')->default(1);
            $table->string('file_hash', 64);
            $table->string('s3_key', 512);
            $table->integer('file_size_bytes');
            $table->string('content_type', 100)->default('application/pdf');

            // QR Code 
            $table->string('qr_nonce', 64)->nullable()->unique();

            // Status fields
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();

            // Metadata & Lifecycle
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->uuid('last_updated_by')->nullable();
            $table->string('update_reason', 500)->nullable();

            // Version history tracking using binary JSON
            $table->jsonb('version_history')->nullable();

            // Multi-column Indexes matching __table_args__
            $table->index(['expires_at', 'revoked_at'], 'idx_certificate_expiry_valid');
            $table->index(['issuer_id', 'revoked_at'], 'idx_certificate_issuer_active');
            $table->index(['id', 'current_version'], 'idx_certificate_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
