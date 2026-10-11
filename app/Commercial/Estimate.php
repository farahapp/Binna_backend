<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estimate — Internal cost estimate for a Tender.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §9.3):
 *  - Commercial owns Estimate.
 *  - Related to Tender context.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No estimation business logic (TASK-004A scope).
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class Estimate extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'estimates';

    protected $fillable = [
        'tender_id',
        'title',
        'description',
        'total_amount',
        'status',
        'prepared_by',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }
}
