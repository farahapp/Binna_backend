<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BudgetLine — A single line item within a Budget.
 *
 * Architectural constraints (TASK-006A):
 *  - Owned by Cost Control module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - cost_code_id is an intra-tenant FK (same schema).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class BudgetLine extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'budget_lines';

    protected $fillable = [
        'budget_id',
        'cost_code_id',
        'description',
        'planned_amount',
        'sort_order',
    ];

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $casts = [
        'planned_amount' => 'decimal:2',
        'sort_order'     => 'integer',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // Relationships

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }
}
