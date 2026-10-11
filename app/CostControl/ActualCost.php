<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActualCost — A recorded actual expenditure against a Budget (CAP-052).
 *
 * Architectural constraints (TASK-006A):
 *  - Owned by Cost Control module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - invoice_id is a UUID reference to Commercial Invoice — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class ActualCost extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'actual_costs';

    protected $fillable = [
        'budget_id',
        'cost_code_id',
        'description',
        'amount',
        'invoice_id',
        'cost_date',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'cost_date'  => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
