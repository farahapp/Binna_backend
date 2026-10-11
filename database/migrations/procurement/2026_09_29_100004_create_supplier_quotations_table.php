<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the supplier_quotations table in the per-tenant schema (CAP-062).
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - rfq_id and supplier_id are intra-module FKs (within Procurement).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: submitted | selected | rejected
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('supplier_quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();
            $table->uuid('rfq_id')->index();
            $table->uuid('supplier_id')->index();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('SAR');

            // Lifecycle status
            $table->string('status', 30)->default('submitted')->index();

            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();

            $table->foreign('rfq_id')
                  ->references('id')->on('rfqs')
                  ->onDelete('cascade');

            $table->foreign('supplier_id')
                  ->references('id')->on('tenant_suppliers')
                  ->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supplier_quotations');
    }
};
