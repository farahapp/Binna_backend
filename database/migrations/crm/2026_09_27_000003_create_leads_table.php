<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the leads table in the per-tenant schema.
 * Applied via CRMServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - Lead is owned by CRM (A2 §2.4): "عميل محتمل لم يتحول بعد".
 *  - A Lead may be converted to a Client — converted_client_id tracks this.
 *  - UUID primary key (TODEC-003 convention).
 *
 * TASK-003A — CRM Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            // Primary key — UUID (TODEC-003 convention)
            $table->uuid('id')->primary();

            // Core identity
            $table->string('name', 255)->index();           // Lead name / company name
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('company', 255)->nullable();     // Company if individual contact

            // Lead source (optional — implementation detail)
            $table->string('source', 100)->nullable();      // e.g. referral, website, cold-call

            // Lifecycle status
            // new | contacted | qualified | converted | lost
            $table->string('status', 30)->default('new')->index();

            // Conversion tracking — when Lead is converted to Client
            $table->uuid('converted_client_id')->nullable()->index();
            $table->timestamp('converted_at')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();

            // Foreign key to clients table (same tenant schema) — nullable, set on conversion
            $table->foreign('converted_client_id')
                  ->references('id')
                  ->on('clients')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
