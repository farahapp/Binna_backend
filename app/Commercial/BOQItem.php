<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQItem — A line item within a BOQ Section.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §10.3):
 *  - Parent: BOQSection.
 *  - Fields: description, unit, quantity, unit_rate, amount, wbs_reference (PHASE 6 §10.3).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No calculation/business rules beyond data model (TASK-004A scope).
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class BOQItem extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'boq_items';

    protected $fillable = [
        'boq_section_id',
        'description',
        'unit',
        'quantity',
        'unit_rate',
        'amount',
        'wbs_reference',
        'sort_order',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_rate'  => 'decimal:4',
        'amount'     => 'decimal:4',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function section(): BelongsTo
    {
        return $this->belongsTo(BOQSection::class, 'boq_section_id');
    }
}
