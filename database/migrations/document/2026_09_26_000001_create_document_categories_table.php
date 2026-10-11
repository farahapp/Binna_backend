<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document categories migration — Per-Tenant Schema.
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_slug', 'name', 'parent_id'], 'doc_cat_tenant_name_parent_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('document_categories');
    }
};
