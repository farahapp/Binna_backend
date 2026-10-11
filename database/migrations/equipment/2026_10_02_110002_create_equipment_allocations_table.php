<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment migration — Per-Tenant Schema.
 *
 * Creates the equipment_allocations table in the per-tenant schema.
 * Applied via EquipmentServiceProvider::loadMigrationsFrom().
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('equipment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('asset_id')->index();   // UUID ref to assets, no FK
            $table->uuid('project_id')->index(); // UUID ref to projects, no FK
            $table->uuid('allocated_by')->nullable(); // actorId

            $table->timestamp('allocated_at');
            $table->timestamp('released_at')->nullable();

            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('equipment_allocations');
    }
};
