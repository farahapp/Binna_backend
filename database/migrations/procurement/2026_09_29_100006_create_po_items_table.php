<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the po_items table in the per-tenant schema (CAP-063).
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - purchase_order_id is an intra-module FK (within Procurement).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('po_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('purchase_order_id')->index();
            $table->text('description');
            $table->decimal('quantity', 10, 3)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);

            $table->foreign('purchase_order_id')
                  ->references('id')->on('purchase_orders')
                  ->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('po_items');
    }
};
