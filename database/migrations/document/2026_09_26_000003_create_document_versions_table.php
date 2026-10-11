<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document versions table — Per-Tenant Schema (metadata only).
 *
 * TASK-002C: Metadata only. storage_path is a nullable placeholder
 * for physical file storage (TASK-002D).
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('document_id')->index();
            $table->integer('version_number');
            $table->text('change_summary')->nullable();
            $table->uuid('created_by')->index();  // references users.id in tenant schema
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();  // bytes, null until file attached
            $table->string('storage_path', 500)->nullable();  // placeholder for TASK-002D
            $table->timestamps();
            $table->unique(['document_id', 'version_number'], 'doc_version_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('document_versions');
    }
};
