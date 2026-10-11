<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Control migration — Per-Tenant Schema.
 *
 * Creates the budget_revisions table in the per-tenant schema.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - revised_by is a UUID reference to IAM User — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budget_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('budget_id')->index();
            $table->unsignedInteger('revision_number')->default(1);
            $table->decimal('previous_amount', 15, 2)->default(0);
            $table->decimal('revised_amount', 15, 2)->default(0);
            $table->text('reason')->nullable();

            // IAM User reference — UUID only, no cross-schema FK
            $table->uuid('revised_by')->nullable()->index();
            $table->timestamp('revised_at')->nullable();

            $table->foreign('budget_id')
                  ->references('id')->on('budgets')
                  ->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budget_revisions');
    }
};
