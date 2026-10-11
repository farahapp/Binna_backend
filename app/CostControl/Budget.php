<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Budget — Cost Control module main budget entity.
 *
 * Architectural constraints (TASK-006A):
 *  - Cost Control owns Budget (OD-CC-001 RESOLVED).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - project_id is a UUID reference to Projects — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft → active → closed
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class Budget extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'budgets';

    protected $fillable = [
        'tenant_slug',
        'project_id',
        'name',
        'description',
        'status',
        'total_amount',
        'currency',
        'fiscal_year',
    ];

    protected $attributes = [
        'status'   => 'draft',
        'currency' => 'SAR',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'fiscal_year'  => 'integer',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class, 'budget_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class, 'budget_id');
    }

    public function actualCosts(): HasMany
    {
        return $this->hasMany(ActualCost::class, 'budget_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isClosed(): bool { return $this->status === 'closed'; }
}
