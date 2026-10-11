<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmploymentRecord — Tracks employment history changes for an Employee.
 *
 * Architectural constraints (TASK-010A):
 *  - Owned by HR module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - recorded_by is a UUID reference to IAM users — no cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class EmploymentRecord extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'employment_records';

    protected $fillable = [
        'tenant_slug',
        'employee_id',
        'record_type',
        'job_title',
        'department',
        'daily_rate',
        'hourly_rate',
        'currency',
        'effective_date',
        'notes',
        'recorded_by',
    ];

    protected $attributes = [
        'currency' => 'SAR',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'daily_rate'     => 'decimal:4',
        'hourly_rate'    => 'decimal:4',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // Relationships

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
