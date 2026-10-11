<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProjectTeam — Team member assignment to a Project.
 *
 * Architectural constraints (TASK-005A / PHASE 6):
 *  - Owned by Projects module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - user_id is a UUID reference to IAM User — no cross-schema FK.
 *  - tenant_slug stored for cross-schema traceability.
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class ProjectTeam extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'project_team';

    protected $fillable = [
        'project_id',
        'tenant_slug',
        'user_id',
        'role',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
