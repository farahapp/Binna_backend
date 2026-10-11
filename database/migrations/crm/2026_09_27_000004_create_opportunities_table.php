<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opportunities migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the opportunities table in the per-tenant schema.
 * Applied via CRMServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - Opportunity is owned by CRM (A2 §2.4).
 *  - Opportunity lifecycle must support: creation, won, lost (TASK-003A scope).
 *  - OpportunityWon / OpportunityLost events are reserved for TASK-003B.
 *  - OD-015: Pipeline/Kanban NOT implemented — List View only.
 *  - UUID primary key (TODEC-003 convention).
 *
 * TASK-003A — CRM Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            // Primary key — UUID (TODEC-003 convention)
            $table->uuid('id')->primary();

            // Relationship to Client (A1 MOD-04 / A2 §2.4)
            $table->uuid('client_id')->nullable()->index();

            // Core fields
            $table->string('title', 255)->index();
            $table->text('description')->nullable();

            // Estimated value (optional at creation)
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('currency', 10)->default('QAR');

            // Lifecycle status — supports Won/Lost events (A1 MOD-04)
            // open | won | lost | cancelled
            $table->string('status', 30)->default('open')->index();

            // Expected close date
            $table->date('expected_close_date')->nullable();

            // Won/Lost tracking — timestamps for lifecycle events
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 500)->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();

            // Foreign key to clients table (same tenant schema)
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
