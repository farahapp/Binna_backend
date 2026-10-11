<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the contacts table in the per-tenant schema.
 * Applied via CRMServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - Contact is owned by CRM (A2 §2.4).
 *  - Contact is associated with a Client (A1 MOD-04: "Contacts").
 *  - client_id references clients.id in the same tenant schema.
 *  - GetContact() contract (A4 §4.1) returns: name, role, email, phone.
 *  - UUID primary key (TODEC-003 convention).
 *
 * TASK-003A — CRM Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            // Primary key — UUID (TODEC-003 convention)
            $table->uuid('id')->primary();

            // Relationship to Client (A1 MOD-04 / A2 §2.4)
            // Nullable: a Contact may exist before being linked to a Client (e.g. Lead contact)
            $table->uuid('client_id')->nullable()->index();

            // Core identity fields (A4 §4.1 GetContact output: name, role, email, phone)
            $table->string('name', 255)->index();
            $table->string('role', 100)->nullable();   // Job title / role
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();

            // Status
            $table->string('status', 20)->default('active')->index(); // active | inactive

            // Notes
            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();

            // Foreign key within the same tenant schema
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
