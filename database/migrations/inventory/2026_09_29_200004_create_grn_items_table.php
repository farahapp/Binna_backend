<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory migration — Per-Tenant Schema.
 *
 * Creates the grn_items table in the per-tenant schema (CAP-070).
 * Applied via InventoryServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - grn_id is an intra-module FK (GoodsReceiptNote).
 *  - inventory_item_id is an intra-module FK (InventoryItem).
 *  - po_item_id is a UUID reference to Procurement POItem — NO cross-schema FK.
 *  - unit_cost is the cost at which this line was received (feeds AVCO).
 *
 * A GRNItem is one line of a GoodsReceiptNote, recording the quantity
 * and cost of a specific InventoryItem received.
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('grn_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Parent GRN — intra-module FK
            $table->uuid('grn_id')->index();

            // Item received — intra-module FK
            $table->uuid('inventory_item_id')->index();

            // Reference to Procurement POItem — UUID only, NO cross-schema FK
            $table->uuid('po_item_id')->nullable()->index();

            // Quantity received on this line
            $table->decimal('quantity_received', 18, 6);

            // Unit cost at receipt — used to compute AVCO (OD-009)
            $table->decimal('unit_cost', 18, 6)->default(0);

            // Derived: line total = quantity_received × unit_cost
            $table->decimal('line_total', 18, 6)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('grn_id')
                  ->references('id')->on('goods_receipt_notes')
                  ->onDelete('cascade');

            $table->foreign('inventory_item_id')
                  ->references('id')->on('inventory_items')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('grn_items');
    }
};
