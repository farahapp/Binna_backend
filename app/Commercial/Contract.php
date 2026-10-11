<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contract — Commercial contract entity.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §12):
 *  - Commercial is the System of Record for contractual obligations.
 *  - client_id is a UUID reference to CRM Client — no cross-schema FK.
 *  - project_id is nullable — Projects module not yet implemented.
 *  - quotation_id references source Quotation.
 *  - boq_id references Contract BOQ.
 *  - No contract workflow logic (TASK-004A scope).
 *
 * Lifecycle: DRAFT → UNDER_REVIEW → APPROVED → ACTIVE → VARIATION_IN_PROGRESS
 *            → COMPLETED → CLOSED / SUSPENDED / TERMINATED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class Contract extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'contracts';

    protected $fillable = [
        'contract_number',
        'client_id',
        'project_id',
        'quotation_id',
        'boq_id',
        'contract_value',
        'approved_variations_value',
        'revised_contract_value',
        'payment_terms',
        'retention_percentage',
        'start_date',
        'end_date',
        'status',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'contract_value'            => 'decimal:4',
        'approved_variations_value' => 'decimal:4',
        'revised_contract_value'    => 'decimal:4',
        'retention_percentage'      => 'decimal:2',
        'start_date'                => 'date',
        'end_date'                  => 'date',
        'created_at'                => 'datetime',
        'updated_at'                => 'datetime',
    ];

    // Relationships

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(BOQ::class, 'boq_id');
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ContractVariation::class, 'contract_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isActive(): bool { return $this->status === 'ACTIVE'; }
    public function isCompleted(): bool { return $this->status === 'COMPLETED'; }
    public function isClosed(): bool { return $this->status === 'CLOSED'; }
    public function isTerminated(): bool { return $this->status === 'TERMINATED'; }
}
