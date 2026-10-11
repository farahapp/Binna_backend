<?php

declare(strict_types=1);

namespace App\CRM;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CRMActivity — CRM interaction history record.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4):
 *  - CRM owns CRMActivity (A1 MOD-04: "تتبع التواصل والتاريخ").
 *  - A2 §2.4: "Client Relationship — تاريخ التواصل والعلاقة".
 *  - Polymorphic subject: linked to Client, Lead, or Opportunity.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - actor_id references users.id in the same tenant schema (IAM).
 *
 * TASK-003A — CRM Domain/Data Foundation
 *
 * @property string $id
 * @property string $subject_type  e.g. 'App\CRM\Client' | 'App\CRM\Lead' | 'App\CRM\Opportunity'
 * @property string $subject_id
 * @property string $type  'call' | 'email' | 'meeting' | 'note' | 'visit' | 'other'
 * @property string $summary
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string|null $actor_id
 * @property string $tenant_slug
 */
class CRMActivity extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'crm_activities';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'type',
        'summary',
        'notes',
        'occurred_at',
        'actor_id',
        'tenant_slug',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Polymorphic subject helper
    // -------------------------------------------------------------------------

    /**
     * Returns the subject model (Client, Lead, or Opportunity).
     * Uses manual resolution to avoid Laravel's morphMap requirement.
     */
    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('subject');
    }
}
