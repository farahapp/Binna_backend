<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DailyReports migration — Per-Tenant Schema.
 *
 * Creates the daily_reports table in the per-tenant schema.
 * Applied via ProjectServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - submitted_by is a UUID reference to IAM User — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-005A — Project Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to projects
            $table->uuid('project_id')->index();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');

            $table->date('report_date')->index();

            // IAM User reference — UUID only, no cross-schema FK
            $table->uuid('submitted_by')->index();

            $table->string('weather', 100)->nullable();
            $table->text('work_done')->nullable();
            $table->text('issues')->nullable();

            $table->unsignedTinyInteger('progress_percentage')->default(0);

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
