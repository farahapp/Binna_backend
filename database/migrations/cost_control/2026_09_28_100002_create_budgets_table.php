<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control migration — Per-Tenant Schema.
 *
 * Creates the budgets table in the per-tenant schema.
 * Applied via CostControlServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - project_id is a UUID reference to Projects — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft | active | closed
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 255)->index();
            $table->text('description')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();

            // Projects reference — UUID only, no cross-schema FK
            $table->uuid('project_id')->nullable()->index();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('SAR');
            $table->unsignedSmallInteger('fiscal_year')->nullable()->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budgets');
    }
};
