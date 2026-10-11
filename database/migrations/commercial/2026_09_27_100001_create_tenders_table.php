<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenders migration — Per-Tenant Schema.
 *
 * Creates the tenders table in the per-tenant schema.
 * Applied via CommercialServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - client_id is a UUID reference to CRM Client — NO cross-schema FK constraint.
 *  - UUID primary key (TODEC-003 convention — HasUuids).
 *
 * Lifecycle: DRAFT | PUBLISHED | SUBMISSION_DEADLINE | EVALUATION | AWARDED | LOST | CANCELLED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('reference_number', 100)->nullable()->index();
            $table->string('title', 255)->index();
            $table->text('description')->nullable();

            // CRM Client reference — UUID only, no cross-schema FK
            $table->uuid('client_id')->nullable()->index();

            // Lifecycle status
            $table->string('status', 30)->default('DRAFT')->index();

            $table->timestamp('submission_deadline')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('awarded_at')->nullable();

            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
