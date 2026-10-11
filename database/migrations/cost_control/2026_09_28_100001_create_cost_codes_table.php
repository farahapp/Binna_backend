<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control migration — Per-Tenant Schema.
 *
 * Creates the cost_codes table in the per-tenant schema.
 * Applied via CostControlServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('cost_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('code', 50)->unique()->index();
            $table->string('name', 255)->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cost_codes');
    }
};
