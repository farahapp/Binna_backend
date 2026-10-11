<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the tenant_suppliers table in the per-tenant schema.
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tenant_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();
            $table->string('name', 255)->index();
            $table->string('code', 50)->nullable()->index();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('tenant_suppliers');
    }
};
