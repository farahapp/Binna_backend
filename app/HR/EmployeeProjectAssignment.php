<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeProjectAssignment — Links an Employee to a Project.
 *
 * Architectural constraints (TASK-010A):
 *  - Owned by HR module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — no cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class EmployeeProjectAssignment extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'employee_project_assignments';

    protected $fillable = [
        'tenant_slug',
        'employee_id',
        'project_id',
        'role_on_project',
        'assigned_date',
        'released_date',
        'status',
        'allocation_percentage',
    ];

    protected $attributes = [
        'status'                => 'active',
        'allocation_percentage' => 100.00,
    ];

    protected $casts = [
        'assigned_date'         => 'date',
        'released_date'         => 'date',
        'allocation_percentage' => 'decimal:2',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    // Relationships

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // Status helpers

    public function isActive(): bool { return $this->status === 'active'; }
    public function isReleased(): bool { return $this->status === 'released'; }
}
