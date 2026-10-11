<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ContractVariations migration — Per-Tenant Schema.
 *
 * OD-013 is SUBSTANTIALLY CLOSED — architecture framework defined.
 * boq_changes stored as JSON (delta BOQ items).
 *
 * Lifecycle: DRAFT | SUBMITTED_TO_CLIENT | CLIENT_REVIEW | APPROVED | REJECTED | PENDING
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_variations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to contracts
            $table->uuid('contract_id')->index();
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');

            $table->string('variation_number', 50)->nullable()->index(); // VO-001, VO-002...
            $table->text('reason')->nullable();
            $table->text('scope_description')->nullable();

            // Delta BOQ items — stored as JSON array
            $table->json('boq_changes')->nullable();

            $table->decimal('value_change', 15, 4)->default(0); // positive = addition, negative = deduction

            $table->string('status', 30)->default('DRAFT')->index();

            // OD-007 Approval reference — UUID string, no cross-schema FK
            $table->uuid('approval_ref')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_variations');
    }
};
