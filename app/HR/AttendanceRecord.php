<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttendanceRecord — Daily attendance record for an Employee.
 *
 * Architectural constraints (TASK-010A):
 *  - Owned by HR module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — no cross-schema FK.
 *  - recorded_by is a UUID reference to IAM users — no cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class AttendanceRecord extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'attendance_records';

    protected $fillable = [
        'tenant_slug',
        'employee_id',
        'attendance_date',
        'status',
        'check_in',
        'check_out',
        'hours_worked',
        'project_id',
        'notes',
        'recorded_by',
    ];

    protected $attributes = [
        'status' => 'present',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'hours_worked'    => 'decimal:2',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // Relationships

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // Status helpers

    public function isPresent(): bool { return $this->status === 'present'; }
    public function isAbsent(): bool { return $this->status === 'absent'; }
    public function isOnLeave(): bool { return $this->status === 'on_leave'; }
    public function isHalfDay(): bool { return $this->status === 'half_day'; }
}
