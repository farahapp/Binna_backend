<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects migration — Per-Tenant Schema.
 *
 * Creates the projects table in the per-tenant schema.
 * Applied via ProjectServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - client_id is a UUID reference to CRM Client — NO cross-schema FK constraint.
 *  - contract_id is a UUID reference to Commercial Contract — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft | active | on_hold | closed
 *
 * TASK-005A — Project Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 255)->index();
            $table->text('description')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();

            // CRM Client reference — UUID only, no cross-schema FK
            $table->uuid('client_id')->nullable()->index();

            // Commercial Contract reference — UUID only, no cross-schema FK
            $table->uuid('contract_id')->nullable()->index();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // IAM User reference — UUID only, no cross-schema FK
            $table->uuid('manager_id')->nullable()->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
