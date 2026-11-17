<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProjectOwnerDecision extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_owner_decisions';

    protected $fillable = [
        'submission_id',
        'review_id',
        'project_identity_id',
        'owner_tenant_slug',
        'decision',
        'comments',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
