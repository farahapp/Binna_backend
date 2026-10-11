<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement migration — Per-Tenant Schema.
 *
 * Creates the purchase_requests table in the per-tenant schema (CAP-061).
 * Applied via ProcurementServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects — NO cross-schema FK.
 *  - approval_request_id is a UUID reference to Approval — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft | submitted | approved | rejected | cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('tenant_slug', 100)->index();

            // Cross-module UUID references — no FK constraints
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('requested_by')->nullable()->index();

            $table->string('title', 255)->index();
            $table->text('description')->nullable();

            // Lifecycle status
            $table->string('status', 30)->default('draft')->index();

            $table->date('required_date')->nullable();

            // Approval reference — UUID only, no cross-schema FK
            $table->uuid('approval_request_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_requests');
    }
};
