<?php

declare(strict_types=1);

namespace App\Subcontracts;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SubcontractProgress — Periodic progress record for a SubcontractAgreement (TASK-009A).
 *
 * Architectural constraints (TASK-009A):
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - subcontract_agreement_id is an intra-module UUID reference (with FK).
 *  - recorded_by is a UUID reference to IAM User — NO cross-schema FK.
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractProgress extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'subcontract_progresses';

    protected $fillable = [
        'tenant_slug',
        'subcontract_agreement_id',
        'progress_period',
        'percentage_complete',
        'cumulative_percentage',
        'amount_certified',
        'cumulative_amount',
        'recorded_by',
        'notes',
    ];

    protected $attributes = [
        'percentage_complete'   => 0,
        'cumulative_percentage' => 0,
        'amount_certified'      => 0,
        'cumulative_amount'     => 0,
    ];

    protected $casts = [
        'progress_period'       => 'string',
        'percentage_complete'   => 'decimal:2',
        'cumulative_percentage' => 'decimal:2',
        'amount_certified'      => 'decimal:4',
        'cumulative_amount'     => 'decimal:4',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    // Relationships

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(SubcontractAgreement::class, 'subcontract_agreement_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SubcontractCertificate::class, 'subcontract_progress_id');
    }
}
