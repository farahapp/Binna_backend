<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CostCode — Cost Control module cost classification entity.
 *
 * Architectural constraints (TASK-006A):
 *  - Cost Control module owns CostCode (A1 MOD-07).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class CostCode extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'cost_codes';

    protected $fillable = [
        'tenant_slug',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'cost_code_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class, 'cost_code_id');
    }

    public function actualCosts(): HasMany
    {
        return $this->hasMany(ActualCost::class, 'cost_code_id');
    }
}
