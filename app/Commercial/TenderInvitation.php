<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenderInvitation — Invitation sent for a specific Tender.
 *
 * Architectural constraints (A1 MOD-05 / PHASE 6 §9):
 *  - Belongs to Tender (Commercial owns both).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No business logic — data model only (TASK-004A scope).
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
class TenderInvitation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'tender_invitations';

    protected $fillable = [
        'tender_id',
        'invitee_name',
        'invitee_email',
        'invitee_type',
        'status',
        'invited_at',
        'responded_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'invited_at'   => 'datetime',
        'responded_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // Relationships

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class, 'tender_id');
    }
}
