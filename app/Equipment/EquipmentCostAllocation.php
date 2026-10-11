<?php

declare(strict_types=1);

namespace App\Equipment;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * EquipmentCostAllocation — tracks equipment cost allocation to projects.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class EquipmentCostAllocation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'equipment_cost_allocations';

    protected $fillable = [
        'asset_id',
        'project_id',
        'cost_code',
        'period_start',
        'period_end',
        'allocated_cost',
        'currency',
        'notes',
        'tenant_slug',
    ];

    protected $attributes = [
        'currency' => 'QAR',
    ];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'allocated_cost' => 'decimal:4',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
