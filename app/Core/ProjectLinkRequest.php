<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ProjectLinkRequest — candidate or explicit link between project identities.
 * Never auto-merges on name alone.
 */
class ProjectLinkRequest extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_link_requests';

    protected $fillable = [
        'source_project_identity_id',
        'target_project_identity_id',
        'property_id',
        'requester_tenant_slug',
        'requester_org_type',
        'match_type',
        'evidence',
        'status',
        'decided_by_tenant_slug',
        'decision_reason',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'evidence' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
