<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts migration — Per-Tenant Schema.
 *
 * Lifecycle: DRAFT | UNDER_REVIEW | APPROVED | ACTIVE | VARIATION_IN_PROGRESS
 *            | COMPLETED | CLOSED | SUSPENDED | TERMINATED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('contract_number', 100)->nullable()->index();

            // CRM Client reference — UUID only, no cross-schema FK
            $table->uuid('client_id')->nullable()->index();

            // Projects reference — nullable (Projects module not yet implemented)
            $table->uuid('project_id')->nullable()->index();

            // Tenant-local FK to quotations
            $table->uuid('quotation_id')->nullable()->index();
            $table->foreign('quotation_id')->references('id')->on('quotations')->onDelete('set null');

            // Tenant-local FK to boqs (Contract BOQ)
            $table->uuid('boq_id')->nullable()->index();
            $table->foreign('boq_id')->references('id')->on('boqs')->onDelete('set null');

            $table->decimal('contract_value', 15, 4)->default(0);
            $table->decimal('approved_variations_value', 15, 4)->default(0);
            $table->decimal('revised_contract_value', 15, 4)->default(0);

            $table->text('payment_terms')->nullable();
            $table->decimal('retention_percentage', 5, 2)->default(0);

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('status', 30)->default('DRAFT')->index();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
