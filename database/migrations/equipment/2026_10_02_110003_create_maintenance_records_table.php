<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment migration — Per-Tenant Schema.
 *
 * Creates the maintenance_records table in the per-tenant schema.
 * Applied via EquipmentServiceProvider::loadMigrationsFrom().
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('maintenance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('asset_id')->index(); // UUID ref to assets, no FK

            $table->string('maintenance_type', 50)->index(); // scheduled | corrective | preventive
            $table->string('status', 30)->default('pending')->index(); // pending | in_progress | completed | cancelled

            $table->date('scheduled_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->decimal('cost', 15, 4)->nullable();
            $table->string('currency', 10)->default('QAR');

            $table->text('description')->nullable();
            $table->string('performed_by', 200)->nullable();
            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('maintenance_records');
    }
};
