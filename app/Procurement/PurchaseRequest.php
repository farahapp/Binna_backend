<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PurchaseRequest — Purchase Requisition entity (CAP-061).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns PurchaseRequest (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - project_id is a UUID reference to Projects — NO cross-schema FK.
 *  - approval_request_id is a UUID reference to Approval — NO cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft → submitted → approved → rejected → cancelled
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class PurchaseRequest extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'purchase_requests';

    protected $fillable = [
        'tenant_slug',
        'project_id',
        'requested_by',
        'title',
        'description',
        'status',
        'required_date',
        'approval_request_id',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $casts = [
        'required_date' => 'date',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // Relationships

    public function rfqs(): HasMany
    {
        return $this->hasMany(RFQ::class, 'purchase_request_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
