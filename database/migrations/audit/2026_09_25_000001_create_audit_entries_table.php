<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit entries migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the audit_entries table in the per-tenant schema.
 * Applied via AuditServiceProvider::loadMigrationsFrom(), following the
 * same pattern as IamServiceProvider for IAM migrations.
 *
 * Ordering: runs AFTER IAM migrations (2026_09_24_000001) because
 * actor_id references the IAM user identity in the same tenant schema.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No updated_at column — records are immutable (NEW-003 / A1 MOD-03).
 *  - actor_id is a UUID referencing users.id (TODEC-003).
 *  - before/after stored as JSON for flexible payload (A2 §2.3).
 *  - tenant_slug stored directly for cross-schema traceability (OD-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            // Primary key — UUID v4 (TODEC-003)
            $table->uuid('id')->primary();

            // Actor identity — references users.id in the same tenant schema (IAM — A2 §2.2)
            // Nullable to allow system-generated events where no user actor exists.
            // Type: uuid — consistent with users.id (TODEC-003).
            $table->uuid('actor_id')->nullable()->index();

            // Event name — e.g. 'auth.login', 'auth.logout', 'iam.permission_changed'
            $table->string('event', 100)->index();

            // Subject of the event — polymorphic-style type + id
            $table->string('subject_type', 100)->nullable();
            $table->uuid('subject_id')->nullable();

            // State snapshot — JSON payloads (nullable; not all events have before/after)
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // Tenant context — stored directly for cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            // created_at only — no updated_at (immutable record — NEW-003)
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
