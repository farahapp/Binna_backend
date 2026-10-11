<?php

declare(strict_types=1);

namespace App\Subcontracts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SubcontractCertificate — Payment certificate issued against a SubcontractProgress (TASK-009A).
 *
 * Architectural constraints (TASK-009A):
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - subcontract_agreement_id is an intra-module UUID reference (with FK).
 *  - subcontract_progress_id is an intra-module UUID reference (with FK, nullable).
 *  - approved_by is a UUID reference to IAM User — NO cross-schema FK.
 *
 * Lifecycle: draft → approved → paid
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractCertificate extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'subcontract_certificates';

    protected $fillable = [
        'tenant_slug',
        'subcontract_agreement_id',
        'subcontract_progress_id',
        'certificate_number',
        'certified_amount',
        'retention_deducted',
        'net_payable',
        'status',
        'approved_by',
        'issued_date',
        'notes',
    ];

    protected $attributes = [
        'status'             => 'draft',
        'certified_amount'   => 0,
        'retention_deducted' => 0,
        'net_payable'        => 0,
    ];

    protected $casts = [
        'certified_amount'   => 'decimal:4',
        'retention_deducted' => 'decimal:4',
        'net_payable'        => 'decimal:4',
        'issued_date'        => 'date',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    // Relationships

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(SubcontractAgreement::class, 'subcontract_agreement_id');
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(SubcontractProgress::class, 'subcontract_progress_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isPaid(): bool { return $this->status === 'paid'; }
}
