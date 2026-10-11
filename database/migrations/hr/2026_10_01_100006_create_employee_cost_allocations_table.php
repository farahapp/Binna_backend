<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the employee_cost_allocations table in the per-tenant schema.
 * Records employee cost allocation to projects for Cost Control integration.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — NO cross-schema FK.
 *  - cost_code_id is a UUID reference to CostControl module — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('employee_cost_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('employee_id')->index();
            $table->date('allocation_date')->index();

            // Projects module reference — UUID only, no cross-schema FK
            $table->uuid('project_id')->index();

            // CostControl module reference — UUID only, no cross-schema FK
            $table->uuid('cost_code_id')->nullable()->index();

            $table->decimal('hours_allocated', 8, 2)->default(0);
            $table->decimal('amount', 15, 4)->default(0);
            $table->string('currency', 10)->default('SAR');
            $table->string('allocation_type', 30)->default('daily')->index(); // daily | hourly | fixed
            $table->text('notes')->nullable();

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
        Schema::connection('tenant')->dropIfExists('employee_cost_allocations');
    }
};
