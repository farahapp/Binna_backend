<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenderResponse — Response to an external client tender.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §9):
 *  - Belongs to Tender (Commercial owns both).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No bid evaluation or award logic (TASK-004A scope).
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class TenderResponse extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'tender_responses';

    protected $fillable = [
        'tender_id',
        'respondent_name',
        'respondent_email',
        'submission_date',
        'status',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'submission_date' => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // Relationships

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }
}
