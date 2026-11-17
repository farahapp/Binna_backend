<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectSubmission extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_submissions';

    protected $fillable = [
        'project_identity_id',
        'submitter_tenant_slug',
        'submitter_participation_id',
        'submission_type',
        'title',
        'body',
        'payload',
        'status',
    ];

    protected $attributes = [
        'status' => 'submitted',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(ProjectReview::class, 'submission_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ProjectOwnerDecision::class, 'submission_id');
    }
}
