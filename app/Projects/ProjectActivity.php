<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProjectActivity — WBS activity/task within a Project.
 *
 * Architectural constraints (TASK-005A / PHASE 6):
 *  - Owned by Projects module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - Supports WBS hierarchy via self-referencing parent_activity_id.
 *
 * Lifecycle: pending → in_progress → completed / cancelled
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class ProjectActivity extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'project_activities';

    protected $fillable = [
        'project_id',
        'tenant_slug',
        'name',
        'description',
        'status',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'progress_percentage',
        'parent_activity_id',
    ];

    protected $attributes = [
        'progress_percentage' => 0,
        'status'              => 'pending',
    ];

    protected $casts = [
        'planned_start'       => 'date',
        'planned_end'         => 'date',
        'actual_start'        => 'date',
        'actual_end'          => 'date',
        'progress_percentage' => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProjectActivity::class, 'parent_activity_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectActivity::class, 'parent_activity_id');
    }

    // Status helpers

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isInProgress(): bool { return $this->status === 'in_progress'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
