<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the employee_project_assignments table in the per-tenant schema.
 * Links employees to projects for project assignment tracking.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('employee_project_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('employee_id')->index();

            // Projects module reference — UUID only, no cross-schema FK
            $table->uuid('project_id')->index();

            $table->string('role_on_project', 150)->nullable();
            $table->date('assigned_date')->index();
            $table->date('released_date')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | released

            // Allocation percentage for cost allocation
            $table->decimal('allocation_percentage', 5, 2)->default(100.00);

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->foreign('employee_id')
                  ->references('id')->on('employees')
                  ->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('employee_project_assignments');
    }
};
