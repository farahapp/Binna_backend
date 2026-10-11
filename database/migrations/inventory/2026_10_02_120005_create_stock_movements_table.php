<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory migration — Per-Tenant Schema.
 *
 * Creates the stock_movements table in the per-tenant schema (CAP-071).
 * Applied via InventoryServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - tenant_slug stored for cross-schema traceability.
 *  - movement_type: in | out | transfer
 *  - from_warehouse_code: source warehouse (null for IN movements)
 *  - to_warehouse_code: destination warehouse (null for OUT movements)
 *
 * TASK-012A — Inventory Domain/Data Foundation (Completion)
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();

            // Item reference
            $table->uuid('inventory_item_id')->index();

            // Movement type: in | out | transfer
            $table->string('movement_type', 20)->index();

            // Quantity and costing
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('total_value', 18, 6)->default(0);

            // Warehouse routing
            $table->string('from_warehouse_code', 100)->nullable(); // null for IN
            $table->string('to_warehouse_code', 100)->nullable();   // null for OUT

            // Source document reference (GRN, PO, project, etc.) — UUID only, no FK
            $table->string('reference_type', 100)->nullable();
            $table->uuid('reference_id')->nullable()->index();

            // Actor and notes
            $table->uuid('performed_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Index for item movement history queries
            $table->index(['inventory_item_id', 'movement_type']);
            $table->index(['inventory_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_movements');
    }
};
