<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quotation — Pricing submitted to client.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §11):
 *  - Commercial owns Quotation.
 *  - client_id is a UUID reference to CRM Client — no cross-schema FK.
 *  - tender_id references parent Tender.
 *  - boq_id references the priced BOQ.
 *  - No Approval integration (TASK-004A scope).
 *
 * Lifecycle: DRAFT → UNDER_REVIEW → APPROVED → SUBMITTED → ACCEPTED / REJECTED / EXPIRED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class Quotation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'quotations';

    protected $fillable = [
        'quotation_number',
        'tender_id',
        'boq_id',
        'client_id',
        'title',
        'total_value',
        'status',
        'quotation_date',
        'validity_date',
        'submitted_at',
        'accepted_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'total_value'    => 'decimal:4',
        'quotation_date' => 'date',
        'validity_date'  => 'date',
        'submitted_at'   => 'datetime',
        'accepted_at'    => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // Relationships

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(BOQ::class, 'boq_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isAccepted(): bool { return $this->status === 'ACCEPTED'; }
    public function isRejected(): bool { return $this->status === 'REJECTED'; }
    public function isExpired(): bool { return $this->status === 'EXPIRED'; }
}
