<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProjectActivities migration — Per-Tenant Schema.
 *
 * Creates the project_activities table in the per-tenant schema.
 * Applied via ProjectServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - Supports WBS hierarchy via self-referencing parent_activity_id (nullable).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: pending | in_progress | completed | cancelled
 *
 * TASK-005A — Project Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to projects
            $table->uuid('project_id')->index();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');

            $table->string('name', 255)->index();
            $table->text('description')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('pending')->index();

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();

            $table->unsignedTinyInteger('progress_percentage')->default(0);

            // WBS hierarchy — self-referencing, nullable
            $table->uuid('parent_activity_id')->nullable()->index();
            $table->foreign('parent_activity_id')->references('id')->on('project_activities')->onDelete('set null');

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
