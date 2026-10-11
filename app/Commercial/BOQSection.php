<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQSection — A section within a BOQ (e.g., Civil Works, MEP).
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §10.3):
 *  - Parent: BOQ.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class BOQSection extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'boq_sections';

    protected $fillable = [
        'boq_id',
        'title',
        'description',
        'sort_order',
        'tenant_slug',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function boq(): BelongsTo
    {
        return $this->belongsTo(BOQ::class, 'boq_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BOQItem::class, 'boq_section_id');
    }
}
