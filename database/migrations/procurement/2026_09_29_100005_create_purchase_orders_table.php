<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the purchase_orders table in the per-tenant schema (CAP-063).
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - supplier_id and purchase_request_id are intra-module FKs.
 *  - approval_request_id is a UUID reference to Approval — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft | submitted | approved | issued | completed | cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();
            $table->uuid('purchase_request_id')->nullable()->index();
            $table->uuid('supplier_id')->index();
            $table->string('po_number', 100)->nullable()->index();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('SAR');
            $table->date('issued_date')->nullable();
            $table->date('expected_delivery_date')->nullable();

            // Approval reference — UUID only, no cross-schema FK
            $table->uuid('approval_request_id')->nullable()->index();

            $table->text('notes')->nullable();

            $table->foreign('supplier_id')
                  ->references('id')->on('tenant_suppliers')
                  ->onDelete('restrict');

            $table->foreign('purchase_request_id')
                  ->references('id')->on('purchase_requests')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_orders');
    }
};
