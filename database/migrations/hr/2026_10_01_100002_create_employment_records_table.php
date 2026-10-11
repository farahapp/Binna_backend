<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the employment_records table in the per-tenant schema.
 * Tracks employment history changes (promotions, transfers, role changes).
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('employment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('employee_id')->index();
            $table->string('record_type', 50)->index(); // hire | promotion | transfer | termination | role_change | rate_change
            $table->string('job_title', 150)->nullable();
            $table->string('department', 100)->nullable();
            $table->decimal('daily_rate', 12, 4)->nullable();
            $table->decimal('hourly_rate', 12, 4)->nullable();
            $table->string('currency', 10)->default('SAR');
            $table->date('effective_date')->index();
            $table->text('notes')->nullable();

            // Recorded by — IAM user UUID, no cross-schema FK
            $table->uuid('recorded_by')->nullable()->index();

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
        Schema::connection('tenant')->dropIfExists('employment_records');
    }
};
