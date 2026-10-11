<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests migration — Per-Tenant Schema (Track 2 — NEW-002).
 *
 * Creates the approval_requests table in the per-tenant schema.
 * Applied via ApprovalServiceProvider::loadMigrationsFrom(), following the
 * same pattern as AuditServiceProvider for Audit migrations.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - requested_by references users.id in the same tenant schema (IAM).
 *  - tenant_slug stored directly for cross-schema traceability (OD-001).
 *  - status stored as string with default 'pending' (cast to ApprovalStatus enum in model).
 *
 * SC-APPROVAL-1 (UNRESOLVED): No approver_id column — approver identity rule is deferred.
 * F-13 (UNRESOLVED): UUID primary key follows HasUuids convention (UUID v7 in practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('approvable_type', 150)->index();
            $table->uuid('approvable_id')->index();
            $table->uuid('requested_by')->index();  // references users.id in tenant schema
            $table->string('status', 20)->default('pending')->index();
            $table->string('tenant_slug', 100)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
