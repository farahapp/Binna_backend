<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeCostAllocation — Records employee cost allocation to projects.
 *
 * Architectural constraints (TASK-010A):
 *  - Owned by HR module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - project_id is a UUID reference to Projects module — no cross-schema FK.
 *  - cost_code_id is a UUID reference to CostControl module — no cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class EmployeeCostAllocation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'employee_cost_allocations';

    protected $fillable = [
        'tenant_slug',
        'employee_id',
        'allocation_date',
        'project_id',
        'cost_code_id',
        'hours_allocated',
        'amount',
        'currency',
        'allocation_type',
        'notes',
    ];

    protected $attributes = [
        'currency'        => 'SAR',
        'allocation_type' => 'daily',
        'hours_allocated' => 0,
        'amount'          => 0,
    ];

    protected $casts = [
        'allocation_date' => 'date',
        'hours_allocated' => 'decimal:2',
        'amount'          => 'decimal:4',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // Relationships

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
