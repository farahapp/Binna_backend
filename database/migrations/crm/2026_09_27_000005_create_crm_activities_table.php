<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Activities migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the crm_activities table in the per-tenant schema.
 * Applied via CRMServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - CRMActivity is owned by CRM (A1 MOD-04: "تتبع التواصل والتاريخ").
 *  - A2 §2.4: "Client Relationship — تاريخ التواصل والعلاقة".
 *  - Polymorphic subject: can be linked to Client, Lead, or Opportunity.
 *  - UUID primary key (TODEC-003 convention).
 *
 * TASK-003A — CRM Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            // Primary key — UUID (TODEC-003 convention)
            $table->uuid('id')->primary();

            // Polymorphic subject — can be linked to Client, Lead, or Opportunity
            // subject_type: 'App\CRM\Client' | 'App\CRM\Lead' | 'App\CRM\Opportunity'
            $table->string('subject_type', 150)->index();
            $table->uuid('subject_id')->index();

            // Activity type — call, email, meeting, note, visit, other
            $table->string('type', 50)->index();

            // Activity summary / description
            $table->string('summary', 500);
            $table->text('notes')->nullable();

            // When the activity occurred
            $table->timestamp('occurred_at')->useCurrent()->index();

            // Actor — references users.id in the same tenant schema (IAM)
            $table->uuid('actor_id')->nullable()->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
