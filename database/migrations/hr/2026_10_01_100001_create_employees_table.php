<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the employees table in the per-tenant schema.
 * Applied via HrServiceProvider::loadMigrationsFrom().
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability (OD-001).
 *  - user_id is a UUID reference to IAM users — NO cross-schema FK constraint.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: active | inactive | terminated
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // IAM User reference — UUID only, no cross-schema FK
            $table->uuid('user_id')->nullable()->index();

            $table->string('employee_number', 50)->unique()->index();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('national_id', 50)->nullable()->index();
            $table->string('nationality', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable()->index();

            // Employment details
            $table->string('job_title', 150)->nullable();
            $table->string('department', 100)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('employment_type', 30)->default('full_time')->index(); // full_time | part_time | contract | daily_worker
            $table->string('status', 20)->default('active')->index(); // active | inactive | terminated

            // Cost allocation — daily/hourly rate for Cost Control integration
            $table->decimal('daily_rate', 12, 4)->nullable();
            $table->decimal('hourly_rate', 12, 4)->nullable();
            $table->string('currency', 10)->default('SAR');

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('employees');
    }
};
