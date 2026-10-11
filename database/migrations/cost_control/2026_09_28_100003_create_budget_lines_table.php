<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control migration — Per-Tenant Schema.
 *
 * Creates the budget_lines table in the per-tenant schema.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - budget_id and cost_code_id are intra-tenant FKs (same schema).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('budget_id')->index();
            $table->uuid('cost_code_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('planned_amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

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
        Schema::connection('tenant')->dropIfExists('budget_lines');
    }
};
