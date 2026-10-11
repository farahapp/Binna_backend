<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SupplierQuotation — Quotation submitted by a supplier in response to an RFQ (CAP-062).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns SupplierQuotation (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - rfq_id and supplier_id are intra-module UUID references (with FK).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: submitted → selected → rejected
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class SupplierQuotation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'supplier_quotations';

    protected $fillable = [
        'tenant_slug',
        'rfq_id',
        'supplier_id',
        'total_amount',
        'currency',
        'status',
        'notes',
        'valid_until',
    ];

    protected $attributes = [
        'status'   => 'submitted',
        'currency' => 'SAR',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'valid_until'  => 'date',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RFQ::class, 'rfq_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(TenantSupplier::class, 'supplier_id');
    }

    // Status helpers

    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isSelected(): bool { return $this->status === 'selected'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
}
