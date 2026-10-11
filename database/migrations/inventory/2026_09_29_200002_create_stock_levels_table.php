<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory migration — Per-Tenant Schema.
 *
 * Creates the stock_levels table in the per-tenant schema (CAP-071).
 * Applied via InventoryServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - inventory_item_id is an intra-module FK.
 *  - AVCO: average_unit_cost maintained per (item × warehouse) — OD-009.
 *  - Negative stock is NOT permitted in V1 — OD-009 §3.1.
 *
 * StockLevel tracks the current quantity and AVCO cost for one item
 * in one warehouse/location within the tenant.
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();
            $table->uuid('inventory_item_id')->index();

            // Warehouse / stock location identifier (free-form string in V1;
            // a dedicated Warehouse entity is not in the V1 authorized scope)
            $table->string('warehouse_code', 100)->default('DEFAULT')->index();

            // Current stock quantity — must never go below 0 in V1 (OD-009 §3.1)
            $table->decimal('quantity_on_hand', 18, 6)->default(0);

            // AVCO: current moving weighted average cost for this item/warehouse
            // Formula: (existing_qty × existing_avg + received_qty × received_cost)
            //          / (existing_qty + received_qty)
            $table->decimal('average_unit_cost', 18, 6)->default(0);

            // Derived: total inventory value = quantity_on_hand × average_unit_cost
            // Stored for performance; must be kept consistent by the service layer.
            $table->decimal('total_value', 18, 6)->default(0);

            $table->timestamps();

            // One stock-level record per (item × warehouse) per tenant
            $table->unique(['tenant_slug', 'inventory_item_id', 'warehouse_code']);

            $table->foreign('inventory_item_id')
                  ->references('id')->on('inventory_items')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_levels');
    }
};
