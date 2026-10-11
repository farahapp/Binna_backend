<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory migration — Per-Tenant Schema.
 *
 * Creates the goods_receipt_notes table in the per-tenant schema (CAP-070).
 * Applied via InventoryServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - purchase_order_id is a UUID reference to Procurement — NO cross-schema FK.
 *  - Inventory owns GRN (A2 data ownership); Procurement owns PO.
 *
 * A GoodsReceiptNote (GRN) records the physical receipt of goods against
 * a Purchase Order. It is the authoritative event that triggers stock updates.
 *
 * Lifecycle: draft → confirmed → cancelled
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('goods_receipt_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();

            // GRN identity
            $table->string('grn_number', 100)->nullable()->index();

            // Reference to Procurement PO — UUID only, NO cross-schema FK
            $table->uuid('purchase_order_id')->nullable()->index();

            // Supplier reference — UUID only, NO cross-schema FK
            $table->uuid('supplier_id')->nullable()->index();

            // Warehouse where goods are received
            $table->string('warehouse_code', 100)->default('DEFAULT')->index();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();
            // draft: being prepared
            // confirmed: goods physically received; stock updated
            // cancelled: GRN voided; stock NOT updated

            // Receipt date
            $table->date('received_date')->nullable();

            // Actor who confirmed the receipt
            $table->uuid('received_by')->nullable()->index();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('goods_receipt_notes');
    }
};
