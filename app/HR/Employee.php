<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee — HR module main entity.
 *
 * Architectural constraints (TASK-010A):
 *  - HR module owns Employee (A2 Domain Ownership Matrix — MOD-11).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - user_id is a UUID reference to IAM users — no cross-schema FK.
 *  - No Payroll/WPS fields — CAP-091 excluded (OD-008 IMPL-BLOCKING).
 *
 * Lifecycle: active → inactive → terminated
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class Employee extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'employees';

    protected $fillable = [
        'tenant_slug',
        'user_id',
        'employee_number',
        'first_name',
        'last_name',
        'national_id',
        'nationality',
        'date_of_birth',
        'gender',
        'phone',
        'email',
        'job_title',
        'department',
        'hire_date',
        'termination_date',
        'employment_type',
        'status',
        'daily_rate',
        'hourly_rate',
        'currency',
    ];

    protected $attributes = [
        'status'          => 'active',
        'employment_type' => 'full_time',
        'currency'        => 'SAR',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'hire_date'        => 'date',
        'termination_date' => 'date',
        'daily_rate'       => 'decimal:4',
        'hourly_rate'      => 'decimal:4',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    // Relationships

    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class, 'employee_id');
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(EmployeeProjectAssignment::class, 'employee_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(EmployeeCostAllocation::class, 'employee_id');
    }

    // Status helpers

    public function isActive(): bool { return $this->status === 'active'; }
    public function isInactive(): bool { return $this->status === 'inactive'; }
    public function isTerminated(): bool { return $this->status === 'terminated'; }
}
