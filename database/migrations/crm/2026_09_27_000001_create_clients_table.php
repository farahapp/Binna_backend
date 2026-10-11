<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the clients table in the per-tenant schema.
 * Applied via CRMServiceProvider::loadMigrationsFrom(), following the
 * same pattern as AuditServiceProvider and ApprovalServiceProvider.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - Supports both company and individual clients (A1 MOD-04 / A2 §2.4).
 *  - UUID primary key (TODEC-003 convention — HasUuids).
 *  - CRM owns Client master data — Commercial/Finance read via GetClient() contract (A4 §4.1).
 *
 * TASK-003A — CRM Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            // Primary key — UUID (TODEC-003 convention)
            $table->uuid('id')->primary();

            // Client type — company or individual (A1 MOD-04)
            $table->string('type', 20)->index(); // 'company' | 'individual'

            // Core identity fields
            $table->string('name', 255)->index();           // Company name or full name
            $table->string('trade_name', 255)->nullable();  // Trade/commercial name (company)
            $table->string('registration_number', 100)->nullable(); // CR / QID number

            // Contact information
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('country', 100)->nullable()->default('Qatar');
            $table->string('city', 100)->nullable();

            // Lifecycle status (A1 MOD-04 — ValidateClient requires status check)
            // active | inactive | blocked
            $table->string('status', 20)->default('active')->index();

            // Notes / description
            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
