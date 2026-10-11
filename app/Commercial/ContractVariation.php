<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContractVariation — Amendment to a Contract.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §13 / OD-013):
 *  - Commercial owns ContractVariation.
 *  - OD-013 is SUBSTANTIALLY CLOSED — architecture framework defined.
 *  - boq_changes stored as JSON (delta BOQ items).
 *  - No multi-tier Approval logic (TASK-004A scope).
 *
 * Lifecycle: DRAFT → SUBMITTED_TO_CLIENT → CLIENT_REVIEW → APPROVED / REJECTED / PENDING
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class ContractVariation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'contract_variations';

    protected $fillable = [
        'contract_id',
        'variation_number',
        'reason',
        'scope_description',
        'boq_changes',
        'value_change',
        'status',
        'approval_ref',
        'approved_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'boq_changes'  => 'array',
        'value_change' => 'decimal:4',
        'approved_at'  => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isApproved(): bool { return $this->status === 'APPROVED'; }
    public function isRejected(): bool { return $this->status === 'REJECTED'; }
}
