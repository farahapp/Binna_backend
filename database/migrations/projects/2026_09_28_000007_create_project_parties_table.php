<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProjectParties migration — Per-Tenant Schema.
 *
 * Creates the project_parties table in the per-tenant schema.
 * Applied via ProjectServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - client_id is a UUID reference to CRM Client — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *  - UNIQUE on (project_id, client_id, role) — one role per client per project.
 *
 * CORE-01C — Project Parties Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            // Tenant-local FK to projects
            $table->uuid('project_id')->index();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');

            // CRM Client reference — UUID only, no cross-schema FK
            $table->uuid('client_id')->index();

            // Party role within the project
            $table->string('role', 100)->index();

            $table->timestamps();

            // Each client can hold a given role at most once per project
            $table->unique(['project_id', 'client_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_parties');
    }
};
