<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment migration — Per-Tenant Schema.
 *
 * Creates the equipment_cost_allocations table in the per-tenant schema.
 * Applied via EquipmentServiceProvider::loadMigrationsFrom().
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('equipment_cost_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('asset_id')->index();   // UUID ref to assets, no FK
            $table->uuid('project_id')->index(); // UUID ref to projects, no FK

            $table->string('cost_code', 100)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('allocated_cost', 15, 4);
            $table->string('currency', 10)->default('QAR');

            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('equipment_cost_allocations');
    }
};
