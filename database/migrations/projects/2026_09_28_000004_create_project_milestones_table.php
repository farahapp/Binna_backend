<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProjectMilestones migration — Per-Tenant Schema.
 *
 * Creates the project_milestones table in the per-tenant schema.
 * Applied via ProjectServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: pending | reached | missed
 *
 * TASK-005A — Project Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to projects
            $table->uuid('project_id')->index();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');

            $table->string('name', 255)->index();
            $table->text('description')->nullable();

            $table->date('due_date')->nullable()->index();
            $table->timestamp('reached_at')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('pending')->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
