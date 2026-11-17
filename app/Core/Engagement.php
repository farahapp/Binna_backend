<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Engagement — contractual/professional relationship between organizations.
 * Distinct from ProjectParticipation.
 */
class Engagement extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'engagements';

    protected $fillable = [
        'project_identity_id',
        'network_award_id',
        'network_tender_id',
        'engagement_type',
        'client_tenant_slug',
        'provider_tenant_slug',
        'client_network_profile_id',
        'provider_network_profile_id',
        'status',
        'contract_value',
        'currency',
        'start_date',
        'end_date',
        'terms',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'QAR',
    ];

    protected $casts = [
        'contract_value' => 'float',
        'terms' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projectIdentity(): BelongsTo
    {
        return $this->belongsTo(ProjectIdentity::class, 'project_identity_id');
    }
}
