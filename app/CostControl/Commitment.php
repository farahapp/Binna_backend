<?php

declare(strict_types=1);

namespace App\CostControl;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commitment — A committed cost against a Budget (CAP-051).
 *
 * Architectural constraints (TASK-006A):
 *  - Owned by Cost Control module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - contract_id is a UUID reference to Commercial Contract — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: pending → approved → cancelled
 *
 * TASK-006A — Cost Control Domain/Data Foundation
 */
class Commitment extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'commitments';

    protected $fillable = [
        'budget_id',
        'cost_code_id',
        'description',
        'amount',
        'status',
        'contract_id',
        'committed_date',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'committed_date' => 'date',
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

    // Status helpers

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
