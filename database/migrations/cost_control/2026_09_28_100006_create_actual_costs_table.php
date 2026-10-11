<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control migration — Per-Tenant Schema.
 *
 * Creates the actual_costs table in the per-tenant schema (CAP-052).
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - invoice_id is a UUID reference to Commercial Invoice — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('actual_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('budget_id')->index();
            $table->uuid('cost_code_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            // Commercial Invoice reference — UUID only, no cross-schema FK
            $table->uuid('invoice_id')->nullable()->index();
            $table->date('cost_date')->nullable();

            $table->foreign('budget_id')
                  ->references('id')->on('budgets')
                  ->onDelete('cascade');

            $table->foreign('cost_code_id')
                  ->references('id')->on('cost_codes')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('actual_costs');
    }
};
