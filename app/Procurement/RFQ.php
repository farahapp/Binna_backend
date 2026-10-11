<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RFQ — Request for Quotation entity (CAP-062).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns RFQ (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - purchase_request_id is an intra-module UUID reference (with FK).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft → sent → closed → cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class RFQ extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'rfqs';

    protected $fillable = [
        'tenant_slug',
        'purchase_request_id',
        'title',
        'description',
        'status',
        'deadline',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $casts = [
        'deadline'   => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class, 'rfq_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSent(): bool { return $this->status === 'sent'; }
    public function isClosed(): bool { return $this->status === 'closed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
