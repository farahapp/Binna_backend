<?php

declare(strict_types=1);

namespace App\Subcontracts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SubcontractAgreement — Subcontract Agreement entity (TASK-009A).
 *
 * Architectural constraints (TASK-009A):
 *  - Subcontracts module owns SubcontractAgreement.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - project_id is a UUID reference — NO cross-schema FK.
 *  - subcontractor_id is a UUID reference — NO cross-schema FK.
 *  - approval_request_id is a UUID reference to Approval — NO cross-schema FK.
 *  - signed_by is a UUID reference to IAM User — NO cross-schema FK.
 *
 * Lifecycle: draft → active → completed → cancelled
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractAgreement extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'subcontract_agreements';

    protected $fillable = [
        'tenant_slug',
        'agreement_number',
        'subcontractor_name',
        'subcontractor_id',
        'project_id',
        'title',
        'scope_summary',
        'contract_value',
        'retention_percentage',
        'currency',
        'status',
        'start_date',
        'end_date',
        'approval_request_id',
        'signed_by',
        'notes',
    ];

    protected $attributes = [
        'status'               => 'draft',
        'currency'             => 'SAR',
        'retention_percentage' => 0,
    ];

    protected $casts = [
        'contract_value'       => 'decimal:4',
        'retention_percentage' => 'decimal:2',
        'start_date'           => 'date',
        'end_date'             => 'date',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    // Relationships

    public function scopes(): HasMany
    {
        return $this->hasMany(SubcontractScope::class, 'subcontract_agreement_id');
    }

    public function progresses(): HasMany
    {
        return $this->hasMany(SubcontractProgress::class, 'subcontract_agreement_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SubcontractCertificate::class, 'subcontract_agreement_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
