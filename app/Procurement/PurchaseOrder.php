<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PurchaseOrder — Purchase Order entity (CAP-063).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns PurchaseOrder (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - supplier_id is an intra-module UUID reference (with FK).
 *  - purchase_request_id is an intra-module UUID reference (with FK).
 *  - approval_request_id is a UUID reference to Approval — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft → submitted → approved → issued → completed → cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class PurchaseOrder extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'purchase_orders';

    protected $fillable = [
        'tenant_slug',
        'purchase_request_id',
        'supplier_id',
        'po_number',
        'status',
        'total_amount',
        'currency',
        'issued_date',
        'expected_delivery_date',
        'approval_request_id',
        'notes',
    ];

    protected $attributes = [
        'status'   => 'draft',
        'currency' => 'SAR',
    ];

    protected $casts = [
        'total_amount'           => 'decimal:2',
        'issued_date'            => 'date',
        'expected_delivery_date' => 'date',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    // Relationships

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(TenantSupplier::class, 'supplier_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(POItem::class, 'purchase_order_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isIssued(): bool { return $this->status === 'issued'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
