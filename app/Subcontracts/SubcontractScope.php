<?php

declare(strict_types=1);

namespace App\Subcontracts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SubcontractScope — Line-item scope of work within a SubcontractAgreement (TASK-009A).
 *
 * Architectural constraints (TASK-009A):
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - subcontract_agreement_id is an intra-module UUID reference (with FK).
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractScope extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'subcontract_scopes';

    protected $fillable = [
        'tenant_slug',
        'subcontract_agreement_id',
        'scope_item_number',
        'description',
        'unit',
        'quantity',
        'unit_rate',
        'total_amount',
    ];

    protected $casts = [
        'quantity'     => 'decimal:4',
        'unit_rate'    => 'decimal:4',
        'total_amount' => 'decimal:4',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(SubcontractAgreement::class, 'subcontract_agreement_id');
    }
}
