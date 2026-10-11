<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Project — Projects module main entity.
 *
 * Architectural constraints (TASK-005A / PHASE 6):
 *  - Projects module owns Project (A2 Domain Ownership Matrix).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - client_id is a UUID reference to CRM Client — no cross-schema FK.
 *  - contract_id is a UUID reference to Commercial Contract — no cross-schema FK.
 *  - No direct dependency on Accounting or Finance modules.
 *
 * Lifecycle: draft → active → on_hold → closed
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class Project extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'projects';

    protected $fillable = [
        'tenant_slug',
        'name',
        'description',
        'status',
        'client_id',
        'contract_id',
        'start_date',
        'end_date',
        'manager_id',
        'progress_percentage',
    ];

    protected $attributes = [
        'status'              => 'draft',
        'progress_percentage' => 0,
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'progress_percentage' => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // Relationships

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class, 'project_id');
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReport::class, 'project_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class, 'project_id');
    }

    public function team(): HasMany
    {
        return $this->hasMany(ProjectTeam::class, 'project_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isOnHold(): bool { return $this->status === 'on_hold'; }
    public function isClosed(): bool { return $this->status === 'closed'; }
}
