<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProjectMilestone — Key milestone within a Project.
 *
 * Architectural constraints (TASK-005A / PHASE 6):
 *  - Owned by Projects module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *
 * Lifecycle: pending → reached / missed
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class ProjectMilestone extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'project_milestones';

    protected $fillable = [
        'project_id',
        'tenant_slug',
        'name',
        'description',
        'due_date',
        'reached_at',
        'status',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'due_date'   => 'date',
        'reached_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    // Status helpers

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isReached(): bool { return $this->status === 'reached'; }
    public function isMissed(): bool { return $this->status === 'missed'; }
}
