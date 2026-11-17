<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProjectReview extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_reviews';

    protected $fillable = [
        'submission_id',
        'project_identity_id',
        'reviewer_tenant_slug',
        'reviewer_org_type',
        'outcome',
        'comments',
        'requires_owner_decision',
    ];

    protected $casts = [
        'requires_owner_decision' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
