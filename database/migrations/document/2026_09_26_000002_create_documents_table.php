<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents table — Per-Tenant Schema (metadata only).
 *
 * TASK-002C: Metadata only. storage_path is a nullable placeholder
 * for physical file storage (TASK-002D).
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('document_number', 100)->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->uuid('created_by')->index();  // references users.id in tenant schema
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();  // bytes, null until file attached
            $table->string('storage_path', 500)->nullable();  // placeholder for TASK-002D
            $table->integer('current_version')->default(1);
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void {
        Schema::dropIfExists('documents');
    }
};
