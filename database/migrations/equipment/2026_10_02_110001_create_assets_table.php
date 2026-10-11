<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment migration — Per-Tenant Schema.
 *
 * Creates the assets table in the per-tenant schema.
 * Applied via EquipmentServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - project_id is a UUID reference — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('asset_code', 50)->unique()->index();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable()->index(); // heavy_machinery | vehicle | tools | it_equipment | other
            $table->string('asset_type', 50)->default('owned')->index(); // owned | leased
            $table->string('status', 30)->default('active')->index(); // active | under_maintenance | decommissioned | disposed

            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_cost', 15, 4)->nullable();
            $table->string('currency', 10)->default('QAR');
            $table->decimal('current_value', 15, 4)->nullable();
            $table->decimal('depreciation_rate', 8, 4)->nullable(); // annual %

            // Project assignment — UUID ref, no FK
            $table->uuid('project_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->date('available_from')->nullable();

            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('assets');
    }
};
