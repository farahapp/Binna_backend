<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the leave_requests table in the per-tenant schema.
 * Stores leave requests with approval integration (OD-007).
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - approval_request_id is a UUID reference to Approval module — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Leave types: annual | sick | emergency | unpaid | other
 * Status: pending | approved | rejected | cancelled
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('employee_id')->index();
            $table->string('leave_type', 30)->index(); // annual | sick | emergency | unpaid | other
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->decimal('days_requested', 5, 2)->default(1);
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();

            // Approval module reference — UUID only, no cross-schema FK (OD-007)
            $table->uuid('approval_request_id')->nullable()->index();

            // Approved/rejected by — IAM user UUID, no cross-schema FK
            $table->uuid('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->foreign('employee_id')
                  ->references('id')->on('employees')
                  ->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('leave_requests');
    }
};
