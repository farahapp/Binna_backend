<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create network_tenders table — global (shared) schema.
 *
 * NETWORK-002 — Network Tender Foundation
 *
 * Architectural constraints (NETWORK-ARCH-001 §4):
 *  - Tender is a cross-tenant entity — lives in the global (mysql) schema.
 *  - NOT in any tenant schema — OD-001 Schema-per-Tenant preserved.
 *  - No direct cross-schema FK to tenant tables.
 *  - OD-006: Free V1 — no billing/monetization fields.
 *  - Lifecycle: draft → published → closed → awarded (foundation only).
 *  - Visibility: public | private | invited | matched.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('network_tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Publisher — FK to network_profiles (global schema)
            // No cross-schema FK — UUID reference only (OD-001 / NETWORK-ARCH-001)
            $table->uuid('publisher_profile_id');
            $table->index('publisher_profile_id');

            // Core metadata
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 100);
            $table->string('region', 100)->nullable();
            $table->timestamp('deadline')->nullable();
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->json('specializations')->nullable();
            $table->json('requirements')->nullable();

            // Participant eligibility
            $table->json('participant_types')->nullable(); // which profile_types may bid

            // Lifecycle status — draft | published | closed | awarded
            $table->enum('status', [
                'draft',
                'published',
                'closed',
                'awarded',
            ])->default('draft');

            // Visibility — public | private | invited | matched
            $table->enum('visibility', [
                'public',
                'private',
                'invited',
                'matched',
            ])->default('public');

            // Matched visibility criteria (criteria-based, no AI in V1)
            $table->json('match_criteria')->nullable();

            // Optional link to ERP Tender (Internal) — nullable, no cross-schema FK
            $table->string('erp_tender_id', 36)->nullable();

            // Audit trail
            $table->string('managed_by_tenant_slug', 100)->nullable();
            $table->index('managed_by_tenant_slug');

            $table->index('status');
            $table->index('visibility');
            $table->index('category');
            $table->index('region');
            $table->index('deadline');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_tenders');
    }
};
