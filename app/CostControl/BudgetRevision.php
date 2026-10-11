<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BudgetRevision — Records a revision/amendment to a Budget.
 *
 * Architectural constraints (TASK-006A):
 *  - Owned by Cost Control module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - revised_by is a UUID reference to IAM User — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class BudgetRevision extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'budget_revisions';

    protected $fillable = [
        'budget_id',
        'revision_number',
        'previous_amount',
        'revised_amount',
        'reason',
        'revised_by',
        'revised_at',
    ];

    protected $casts = [
        'revision_number'  => 'integer',
        'previous_amount'  => 'decimal:2',
        'revised_amount'   => 'decimal:2',
        'revised_at'       => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    // Relationships

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
