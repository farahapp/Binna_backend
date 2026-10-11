<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory migration — Per-Tenant Schema.
 *
 * Creates the inventory_items table in the per-tenant schema (CAP-070 / CAP-071).
 * Applied via InventoryServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - tenant_slug stored for cross-schema traceability.
 *  - Inventory owns InventoryItem (A2 data ownership).
 *
 * An InventoryItem is the master catalogue entry for a stockable material/item.
 * It is the anchor for StockLevel (per warehouse) and GRN lines.
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();

            // Item identity
            $table->string('item_code', 100)->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 50);   // e.g. m3, kg, pcs, m2

            // Valuation — AVCO per OD-009
            // average_unit_cost is the current moving weighted average cost
            $table->decimal('average_unit_cost', 18, 6)->default(0);

            // Status
            $table->string('status', 30)->default('active')->index(); // active | inactive

            $table->timestamps();

            // item_code must be unique per tenant (enforced at application layer;
            // unique index here covers the physical schema)
            $table->unique(['tenant_slug', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('inventory_items');
    }
};
