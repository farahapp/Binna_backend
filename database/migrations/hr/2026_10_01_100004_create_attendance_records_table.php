<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR migration — Per-Tenant Schema.
 *
 * Creates the attendance_records table in the per-tenant schema.
 * Stores daily attendance data for employees.
 *
 * Architectural constraints:
 *  - Table belongs to the tenant schema (NOT shared/global) — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('employee_id')->index();
            $table->date('attendance_date')->index();
            $table->string('status', 20)->default('present')->index(); // present | absent | half_day | on_leave | holiday
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->decimal('hours_worked', 5, 2)->nullable();

            // Project reference for cost allocation — UUID only, no cross-schema FK
            $table->uuid('project_id')->nullable()->index();

            $table->text('notes')->nullable();

            // Recorded by — IAM user UUID, no cross-schema FK
            $table->uuid('recorded_by')->nullable()->index();

            // Tenant context — cross-schema traceability (OD-001)
            $table->string('tenant_slug', 100)->index();

            $table->foreign('employee_id')
                  ->references('id')->on('employees')
                  ->onDelete('cascade');

            // Unique constraint: one attendance record per employee per day
            $table->unique(['employee_id', 'attendance_date'], 'uq_attendance_employee_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('attendance_records');
    }
};
