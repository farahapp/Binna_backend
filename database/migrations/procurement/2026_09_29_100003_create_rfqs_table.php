<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the rfqs table in the per-tenant schema (CAP-062).
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - purchase_request_id is an intra-module FK (within Procurement).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft | sent | closed | cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('rfqs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();
            $table->uuid('purchase_request_id')->nullable()->index();
            $table->string('title', 255)->index();
            $table->text('description')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();

            $table->date('deadline')->nullable();

            $table->foreign('purchase_request_id')
                  ->references('id')->on('purchase_requests')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('rfqs');
    }
};
