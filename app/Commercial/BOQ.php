<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQ — Bill of Quantities.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §10):
 *  - Commercial owns BOQ.
 *  - Supports three contexts: tender_boq | contract_boq | variation_boq (PHASE 6 §10.2).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - tender_id / contract_id / variation_id are nullable UUID references (context-dependent).
 *  - No pricing/markup business logic (TASK-004A scope).
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class BOQ extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'boqs';

    protected $fillable = [
        'type',
        'tender_id',
        'contract_id',
        'variation_id',
        'title',
        'status',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function sections(): HasMany
    {
        return $this->hasMany(BOQSection::class, 'boq_id');
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    // Type helpers

    public function isTenderBOQ(): bool { return $this->type === 'tender_boq'; }
    public function isContractBOQ(): bool { return $this->type === 'contract_boq'; }
    public function isVariationBOQ(): bool { return $this->type === 'variation_boq'; }
}
