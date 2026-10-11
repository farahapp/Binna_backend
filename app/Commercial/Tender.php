<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tender — Commercial entity for bid/tender documents.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §9):
 *  - Commercial owns Tender (A2 Domain Ownership Matrix).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - client_id is a UUID reference to CRM Client — no cross-schema FK.
 *
 * Lifecycle: DRAFT → PUBLISHED → SUBMISSION_DEADLINE → EVALUATION → AWARDED / LOST / CANCELLED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class Tender extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'tenders';

    protected $fillable = [
        'reference_number',
        'title',
        'description',
        'client_id',
        'status',
        'submission_deadline',
        'published_at',
        'awarded_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'submission_deadline' => 'datetime',
        'published_at'        => 'datetime',
        'awarded_at'          => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // Relationships

    public function invitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class, 'tender_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TenderResponse::class, 'tender_id');
    }

    public function boq(): HasOne
    {
        return $this->hasOne(BOQ::class, 'tender_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'tender_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isPublished(): bool { return $this->status === 'PUBLISHED'; }
    public function isAwarded(): bool { return $this->status === 'AWARDED'; }
    public function isCancelled(): bool { return $this->status === 'CANCELLED'; }
}
